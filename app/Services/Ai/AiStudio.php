<?php

namespace App\Services\Ai;

use App\Broadcasting\Channels;
use App\Http\Resources\AiJobResource;
use App\Jobs\AddMusicVersion;
use App\Jobs\RunAiJob;
use App\Models\AiCreditEntry;
use App\Models\AiJob;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackVersion;
use App\Models\User;
use App\Services\Ai\Music\MusicVersions;
use App\Services\Ai\Tasks\GenerateMusic;
use App\Services\Ai\Tasks\HandlesFailure;
use App\Services\Realtime\Realtime;
use App\Services\Studio\TrackHistory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Starts AI jobs, on a project track or on their own like artwork, charging
 * their credits up front, and runs them, refunding the credits when the work
 * fails.
 */
class AiStudio
{
    /**
     * Jobs one person can have waiting or running at once.
     */
    public const MAX_UNFINISHED = 3;

    public function __construct(
        protected MusicVersions $versions,
        protected AiJobStops $stops,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function start(User $user, ?ProjectTrack $track, string $kind, array $input): AiJob
    {
        $credits = Credits::for($user);
        $credits->ensureAccess();

        if (AiJob::query()->where('user_id', $user->id)->unfinished()->count() >= self::MAX_UNFINISHED) {
            throw ValidationException::withMessages(['ai' => 'Wait for your other AI jobs to finish first.']);
        }

        $job = new AiJob(['user_id' => $user->id, 'project_track_id' => $track?->id, 'kind' => $kind, 'input' => $input]);
        $task = $job->task();
        $engine = $task->engine($input);

        if (! $engine->available()) {
            throw ValidationException::withMessages(['ai' => "This AI tool isn't available right now."]);
        }

        $job = DB::transaction(function () use ($job, $task, $credits, $input) {
            $job->credits = $task->credits($input);
            $job->save();

            if ($job->credits > 0) {
                $credits->spend($job->credits, $task->describe($input), $job);
            }

            return $job;
        });

        RunAiJob::dispatch($job)
            ->afterCommit();

        $this->announce($job);

        return $job;
    }

    public function run(AiJob $job): AiJob
    {
        if (! $this->claim($job, ['started_at' => now()])) {
            return $job->refresh();
        }

        $job->refresh();
        $this->announce($job);

        $history = $job->track ? TrackHistory::for($job->track) : null;
        $history?->baseline();

        try {
            $output = $this->stops->watch($job, fn() => $job
                ->task()
                ->handle($job));
        } catch (Throwable $e) {
            return $this->fail($job, $e);
        }

        $job->forceFill(['status' => AiJob::SUCCEEDED, 'finished_at' => now()]);

        return $this->publish($job, $history, $output);
    }

    /**
     * Stop a job and refund it. One still queued stops now; a running one
     * stops at the next check of its provider client.
     */
    public function stop(AiJob $job): void
    {
        if ($this->claim($job)) {
            $this->fail($job->refresh(), new AiJobStopped);

            return;
        }

        if ($job->refresh()->status === AiJob::RUNNING) {
            $this->stops->request($job);
        }
    }

    /**
     * Move a queued job to running, and say whether this call did it.
     *
     * @param  array<string, mixed>  $values
     */
    protected function claim(AiJob $job, array $values = []): bool
    {
        $claimed = AiJob::query()
            ->whereKey($job->id)
            ->where('status', AiJob::QUEUED)
            ->update([...$values, 'status' => AiJob::RUNNING, 'updated_at' => now()]);

        return $claimed > 0;
    }

    /**
     * Start adding the version of a song its owner picked to the track.
     */
    public function choose(AiJob $job, int $index): AiJob
    {
        $job = DB::transaction(function () use ($job, $index) {
            $job = AiJob::query()
                ->lockForUpdate()
                ->findOrFail($job->id);

            if (isset($job->output['choosing'])) {
                throw ValidationException::withMessages(['version' => 'A version is already on its way to the track.']);
            }

            $this->versions->find($job, $index);

            $job
                ->forceFill(['output' => [...$job->output, 'choosing' => $index]])
                ->save();

            AddMusicVersion::dispatch($job)
                ->afterCommit();

            return $job;
        });

        $this->announce($job);

        return $job;
    }

    /**
     * Add the picked version to the track. When that fails the pick is
     * undone so the owner can try again.
     */
    public function addVersion(AiJob $job): AiJob
    {
        $index = $job->output['choosing'] ?? null;

        if ($index === null) {
            return $job;
        }

        $history = TrackHistory::for($job->track);
        $history->baseline();

        try {
            $output = app(GenerateMusic::class)
                ->choose($job, (int) $index);
        } catch (Throwable $e) {
            $this->log($job, $e, 'Add version');

            $job
                ->forceFill([
                    'output' => array_diff_key($job->output, ['choosing' => true]),
                    'failure' => 'Add version: ' . $this->reason($e),
                ])
                ->save();
            $this->announce($job);

            return $job;
        }

        $output = [...array_diff_key($job->output, ['choosing' => true]), ...$output, 'chosen' => (int) $index];

        return $this->publish($job, $history, $output);
    }

    /**
     * Save what a job made, record the track's new version and tell everyone
     * about the stems it added.
     *
     * @param  array<string, mixed>  $output
     */
    protected function publish(AiJob $job, ?TrackHistory $history, array $output): AiJob
    {
        $job
            ->forceFill(['output' => $output])
            ->save();

        $history?->record($job->user, ProjectTrackVersion::ADDED);

        foreach ($output['stems'] ?? [] as $stemId) {
            Realtime::send(Channels::projectTrack($job->track), 'stem.added', [
                'track_id' => $job->project_track_id,
                'stem_id' => $stemId,
            ], $job->user);
        }

        $this->announce($job);

        return $job;
    }

    /**
     * Mark a job failed and give its credits back.
     */
    public function fail(AiJob $job, Throwable|string $error): AiJob
    {
        if (! $error instanceof AiJobStopped) {
            $this->log($job, $error);
        }

        $job->forceFill([
            'status' => AiJob::FAILED,
            'error' => $error instanceof AiJobStopped
                ? 'Stopped before it finished. Your credits were refunded.'
                : 'Something went wrong while making this. Your credits were refunded.',
            'failure' => $this->reason($error),
            'finished_at' => now(),
        ])->save();

        $spend = $job
            ->creditEntries()
            ->where('type', AiCreditEntry::SPEND)
            ->first();

        if ($spend) {
            Credits::for($job->user)->refund($spend, 'Refund: ' . $job->task()->describe($job->input ?? []));
        }

        $task = $job->task();

        if ($task instanceof HandlesFailure) {
            $task->failed($job);
        }

        $this->announce($job);

        return $job;
    }

    /**
     * Note an optional step of a running job that failed, giving back the
     * credits it cost while the rest of the job carries on.
     */
    public function failStep(AiJob $job, Throwable $error, string $step, int $credits): void
    {
        $this->log($job, $error, $step);

        $job
            ->forceFill(['failure' => "{$step}: " . $this->reason($error)])
            ->save();

        $spend = $job
            ->creditEntries()
            ->where('type', AiCreditEntry::SPEND)
            ->first();

        if ($spend && $credits > 0) {
            Credits::for($job->user)->refund($spend, "Refund: {$step}", $credits);
        }
    }

    protected function log(AiJob $job, Throwable|string $error, ?string $step = null): void
    {
        Log::error('AI job failed', array_filter([
            'job' => $job->id,
            'kind' => $job->kind,
            'step' => $step,
            'error' => $error instanceof Throwable ? $error->getMessage() : $error,
            'exception' => $error instanceof Throwable ? $error : null,
        ]));
    }

    protected function reason(Throwable|string $error): string
    {
        $reason = match (true) {
            $error instanceof RequestException => "HTTP {$error->response->status()}: " . ($error->response->json('detail') ?? $error->response->body()),
            $error instanceof Throwable => class_basename($error) . ': ' . $error->getMessage(),
            default => $error,
        };

        return str($reason)
            ->limit(2000)
            ->toString();
    }

    /**
     * Tell the job's owner, and the track's collaborators when it has one,
     * how it's going.
     */
    protected function announce(AiJob $job): void
    {
        Realtime::send(
            [$job->project_track_id ? Channels::projectTrack($job->project_track_id) : null, Channels::user($job->user_id)],
            'ai.job',
            (new AiJobResource($job))->resolve(),
            $job->user,
        );
    }
}
