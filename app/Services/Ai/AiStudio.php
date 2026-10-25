<?php

namespace App\Services\Ai;

use App\Broadcasting\Channels;
use App\Jobs\RunAiJob;
use App\Models\AiCreditEntry;
use App\Models\AiJob;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackVersion;
use App\Models\User;
use App\Services\Realtime\Realtime;
use App\Services\Studio\TrackHistory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Starts AI jobs on a project track, charging their credits up front, and
 * runs them, refunding the credits when the work fails.
 */
class AiStudio
{
    /**
     * Jobs one person can have waiting or running at once.
     */
    public const MAX_UNFINISHED = 3;

    /**
     * @param  array<string, mixed>  $input
     */
    public function start(User $user, ProjectTrack $track, string $kind, array $input): AiJob
    {
        $credits = Credits::for($user);
        $credits->ensureAccess();

        if (AiJob::query()->where('user_id', $user->id)->unfinished()->count() >= self::MAX_UNFINISHED) {
            throw ValidationException::withMessages(['ai' => 'Wait for your other AI jobs to finish first.']);
        }

        $job = new AiJob(['user_id' => $user->id, 'project_track_id' => $track->id, 'kind' => $kind, 'input' => $input]);
        $task = $job->task();

        $job = DB::transaction(function () use ($job, $task, $credits, $input) {
            $job->credits = $task->credits($input);
            $job->save();

            $credits->spend($job->credits, $task->describe($input), $job);

            return $job;
        });

        RunAiJob::dispatch($job)->afterCommit();

        $this->announce($job);

        return $job;
    }

    public function run(AiJob $job): AiJob
    {
        if ($job->status !== AiJob::QUEUED) {
            return $job;
        }

        $job->forceFill(['status' => AiJob::RUNNING, 'started_at' => now()])->save();
        $this->announce($job);

        $history = TrackHistory::for($job->track);
        $history->baseline();

        try {
            $output = $job->task()->handle($job);
        } catch (Throwable $e) {
            return $this->fail($job, $e);
        }

        $job->forceFill(['status' => AiJob::SUCCEEDED, 'output' => $output, 'finished_at' => now()])->save();

        $history->record($job->user, ProjectTrackVersion::ADDED);

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
        $this->log($job, $error);

        $job->forceFill([
            'status' => AiJob::FAILED,
            'error' => 'Something went wrong while making this. Your credits were refunded.',
            'failure' => $this->reason($error),
            'finished_at' => now(),
        ])->save();

        $spend = $job->creditEntries()->where('type', AiCreditEntry::SPEND)->first();

        if ($spend) {
            Credits::for($job->user)->refund($spend, 'Refund: '.$job->task()->describe($job->input ?? []));
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

        $job->forceFill(['failure' => "{$step}: ".$this->reason($error)])->save();

        $spend = $job->creditEntries()->where('type', AiCreditEntry::SPEND)->first();

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
            $error instanceof RequestException => "HTTP {$error->response->status()}: ".($error->response->json('detail') ?? $error->response->body()),
            $error instanceof Throwable => class_basename($error).': '.$error->getMessage(),
            default => $error,
        };

        return str($reason)->limit(2000)->toString();
    }

    /**
     * Tell the track's collaborators and the job's owner how it's going.
     */
    protected function announce(AiJob $job): void
    {
        Realtime::send(
            [Channels::projectTrack($job->project_track_id), Channels::user($job->user_id)],
            'ai.job',
            $job->present(),
            $job->user,
        );
    }
}
