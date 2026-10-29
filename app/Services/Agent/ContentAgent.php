<?php

namespace App\Services\Agent;

use App\Broadcasting\Channels;
use App\Enums\Feature;
use App\Jobs\AdvanceAgentRun;
use App\Models\AgentRun;
use App\Models\AgentStep;
use App\Models\AiJob;
use App\Models\User;
use App\Services\Ai\AiStudio;
use App\Services\Ai\Cloudflare\CloudflareException;
use App\Services\Ai\Credits;
use App\Services\Ai\Replicate\ReplicateException;
use App\Services\Ai\Suno\SunoException;
use App\Services\Limits;
use App\Services\Realtime\Realtime;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * The content agent: admins, the users of agent accounts and creators on a
 * plan with the content agent queue prompts. Runs are planned and built on
 * the account that owns them, one at a time for each account, in the order
 * they were queued.
 */
class ContentAgent
{
    /** Runs planned or built at once, across all accounts */
    public const MAX_ACTIVE = 5;

    /** Seconds one pass over a run may keep working before it hands over */
    public const PASS_SECONDS = 90;

    /** Seconds between passes while a run waits on queued work */
    public const WAIT_SECONDS = 15;

    /** Minutes a step may wait on queued work before it fails */
    public const STEP_TIMEOUT = 60;

    public function __construct(
        protected AgentPlanner $planner,
        protected AiStudio $studio,
        protected AgentRunParts $parts,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function queue(User $by, User $owner, string $prompt, array $options): AgentRun
    {
        if (! $owner->usesContentAgent()) {
            throw ValidationException::withMessages(['owner_id' => 'This account can\'t use the content agent.']);
        }

        Credits::for($owner)->ensureAccess('owner_id');

        $run = AgentRun::query()->create([
            'user_id' => $by->id,
            'owner_id' => $owner->id,
            'prompt' => trim($prompt),
            'options' => $options,
        ]);

        $this->announce($run);
        $this->next();

        return $run->refresh();
    }

    /**
     * Start queued runs, oldest first, one at a time for each account and at
     * most MAX_ACTIVE at once. The first run started is returned.
     */
    public function next(): ?AgentRun
    {
        $first = null;

        for ($i = 0; $i < self::MAX_ACTIVE; $i++) {
            $run = $this->startOne();

            if (! $run) {
                break;
            }

            $first ??= $run;
        }

        return $first;
    }

    /**
     * Start the oldest queued run of an account with no run being planned or
     * built, while fewer than MAX_ACTIVE are.
     */
    protected function startOne(): ?AgentRun
    {
        $run = DB::transaction(function () {
            $busy = AgentRun::query()
                ->active()
                ->lockForUpdate()
                ->pluck('owner_id');

            if ($busy->count() >= self::MAX_ACTIVE) {
                return null;
            }

            $run = AgentRun::query()
                ->where('status', AgentRun::QUEUED)
                ->whereNotIn('owner_id', $busy)
                ->oldest('id')
                ->lockForUpdate()
                ->first();

            $run
                ?->forceFill([
                    'status' => $run->plan ? AgentRun::RUNNING : AgentRun::PLANNING,
                    'started_at' => $run->started_at ?? now(),
                ])
                ->save();

            return $run;
        });

        if ($run) {
            AdvanceAgentRun::dispatch($run)
                ->afterCommit();

            $this->announce($run);
        }

        return $run;
    }

    /**
     * Plan the run or do as much of its steps as is ready, then come back
     * later for the rest.
     */
    public function advance(AgentRun $run): void
    {
        $lock = Cache::lock("agent-run:{$run->id}", self::PASS_SECONDS * 3);

        if (! $lock->get()) {
            return;
        }

        try {
            $run->refresh();

            if ($run->status === AgentRun::PLANNING) {
                $this->plan($run);
            }

            if ($run->status === AgentRun::RUNNING) {
                $this->work($run);
            }
        } finally {
            $lock->release();
        }

        $this->announce($run);

        if ($run->isActive()) {
            AdvanceAgentRun::dispatch($run)
                ->delay(now()->addSeconds(self::WAIT_SECONDS));
        } else {
            $this->next();
        }
    }

    /**
     * Stop a run, queued or under way. Its open steps are skipped and the AI
     * jobs they wait on are stopped and refunded; work already done stays.
     */
    public function cancel(AgentRun $run): AgentRun
    {
        $cancelled = AgentRun::query()
            ->whereKey($run->id)
            ->whereIn('status', [AgentRun::QUEUED, ...AgentRun::ACTIVE])
            ->update(['status' => AgentRun::CANCELLED, 'finished_at' => now(), 'updated_at' => now()]);

        $run->refresh();

        if (! $cancelled) {
            return $run;
        }

        $this->halt($run);
        $this->announce($run);
        $this->next();

        return $run;
    }

    /**
     * Skip a stopped run's open steps and stop the AI jobs they wait on.
     */
    protected function halt(AgentRun $run): void
    {
        $jobs = $run
            ->steps()
            ->open()
            ->whereNotNull('output->ai_job_id')
            ->toBase()
            ->select('output->ai_job_id as ai_job_id')
            ->pluck('ai_job_id');

        $run
            ->steps()
            ->open()
            ->update(['status' => AgentStep::SKIPPED]);

        AiJob::query()
            ->whereKey($jobs)
            ->unfinished()
            ->lazyById(50)
            ->each(fn(AiJob $job) => $this->studio->stop($job));
    }

    /**
     * Take a part, like an album or a track, out of a failed or stopped run
     * so a retry carries on without it.
     */
    public function remove(AgentRun $run, string $part): AgentRun
    {
        if (! in_array($run->status, [AgentRun::FAILED, AgentRun::CANCELLED], true)) {
            throw ValidationException::withMessages(['run' => 'Only failed or stopped runs can have parts removed.']);
        }

        $this->parts->remove($run, $part);
        $this->announce($run);

        return $run->refresh();
    }

    /**
     * Queue a failed or cancelled run again. Steps that are done stay done,
     * so it carries on where it stopped.
     */
    public function retry(AgentRun $run): AgentRun
    {
        if (! in_array($run->status, [AgentRun::FAILED, AgentRun::CANCELLED], true)) {
            throw ValidationException::withMessages(['run' => 'Only failed or cancelled runs can be retried.']);
        }

        $run
            ->steps()
            ->where('status', AgentStep::FAILED)
            ->update(['status' => AgentStep::PENDING, 'output' => null, 'error' => null, 'started_at' => null]);

        $jobs = $run
            ->steps()
            ->where('status', AgentStep::SKIPPED)
            ->whereNotNull('output->ai_job_id')
            ->toBase()
            ->select(['id', 'output->ai_job_id as ai_job_id'])
            ->pluck('ai_job_id', 'id')
            ->map(fn($id) => (int) $id);

        $stopped = AiJob::query()
            ->whereKey($jobs->values())
            ->where('status', AiJob::FAILED)
            ->pluck('id');

        $restart = $jobs
            ->filter(fn(int $job) => $stopped->contains($job))
            ->keys();

        // Steps whose AI job was stopped with the run start their work over.
        AgentStep::query()
            ->whereKey($restart)
            ->update(['output' => null, 'started_at' => null]);

        $run
            ->steps()
            ->where('status', AgentStep::SKIPPED)
            ->update(['status' => AgentStep::PENDING, 'started_at' => null]);

        $run
            ->forceFill(['status' => AgentRun::QUEUED, 'error' => null, 'failure' => null, 'finished_at' => null])
            ->save();

        $this->announce($run);
        $this->next();

        return $run->refresh();
    }

    /**
     * Ask for the plan, check the owner can afford and hold it, and lay out its steps.
     */
    protected function plan(AgentRun $run): void
    {
        try {
            $plan = $this->planner->plan($run);
            $this->ensureFits($run, $plan);
        } catch (Throwable $e) {
            $this->fail($run, $e);

            return;
        }

        DB::transaction(function () use ($run, $plan) {
            $status = AgentRun::query()
                ->whereKey($run->id)
                ->lockForUpdate()
                ->value('status');

            if ($status !== AgentRun::PLANNING) {
                $run->status = $status;

                return;
            }

            foreach ($this->planner->steps($run, $plan) as $position => $step) {
                $run
                    ->steps()
                    ->create([...$step, 'position' => $position]);
            }

            $run
                ->forceFill(['plan' => $plan, 'status' => AgentRun::RUNNING])
                ->save();
        });
    }

    /**
     * Stop a plan the owner hasn't the credits, projects or songs left for.
     *
     * @param  array{artists: list<array<string, mixed>>, albums: list<array<string, mixed>>, releases: list<array<string, mixed>>, songs: list<array<string, mixed>>}  $plan
     */
    protected function ensureFits(AgentRun $run, array $plan): void
    {
        $owner = $run->owner;
        $limits = Limits::for($owner);
        $needed = $this->planner->estimate($run, $plan);
        $balance = Credits::for($owner)->total();

        if ($balance < $needed) {
            throw new RuntimeException("The plan needs about {$needed} credits, but the account has {$balance}.");
        }

        $projects = $limits->get(Feature::MAX_PROJECTS);
        $newProjects = count($plan['albums']);

        $hasProjects = $owner
            ->projects()
            ->count();

        if ($projects > 0 && $newProjects > 0 && $hasProjects + $newProjects > $projects) {
            throw new RuntimeException("The plan needs {$newProjects} new projects, more than the account's plan allows ({$projects}).");
        }

        $songs = $limits->get(Feature::MAX_SONGS);
        $newSongs = count($plan['songs']) + collect($plan['albums'])
            ->flatMap(fn(array $album) => $album['tracks'])
            ->reject(fn(array $track) => $track['instrumental'])
            ->count();

        $hasSongs = $owner
            ->songs()
            ->count();

        if ($songs > 0 && $newSongs > 0 && $hasSongs + $newSongs > $songs) {
            throw new RuntimeException("The plan needs {$newSongs} new compositions, more than the account's plan allows ({$songs}).");
        }
    }

    /**
     * Advance every open step whose steps before it are done, until the pass
     * runs out of time.
     */
    protected function work(AgentRun $run): void
    {
        $started = microtime(true);

        $done = $run
            ->steps()
            ->where('status', AgentStep::DONE)
            ->pluck('key')
            ->flip();

        $open = $run
            ->steps()
            ->open()
            ->orderBy('position')
            ->get();

        foreach ($open as $step) {
            if (microtime(true) - $started > self::PASS_SECONDS) {
                return;
            }

            if (! $this->stillRunning($run)) {
                return;
            }

            if (collect($step->depends_on ?? [])->contains(fn(string $key) => ! $done->has($key))) {
                continue;
            }

            $step->setRelation('run', $run);

            if (! $this->step($step)) {
                $this->fail($run, $step->error ?? 'A step failed.', $step);

                return;
            }

            if ($step->status === AgentStep::DONE) {
                $done[$step->key] = true;
            }
        }

        if (! $this->stillRunning($run)) {
            return;
        }

        $open = $run
            ->steps()
            ->open()
            ->exists();

        if (! $open) {
            $run
                ->forceFill(['status' => AgentRun::SUCCEEDED, 'finished_at' => now()])
                ->save();
        }
    }

    /**
     * Whether the run is still being built. A run cancelled during the pass
     * has the steps and AI jobs the pass started since stopped too.
     */
    protected function stillRunning(AgentRun $run): bool
    {
        $run->status = AgentRun::query()
            ->whereKey($run->id)
            ->value('status');

        if ($run->status === AgentRun::CANCELLED) {
            $this->halt($run);
        }

        return $run->status === AgentRun::RUNNING;
    }

    /**
     * Advance one step and say whether it is still on track.
     */
    protected function step(AgentStep $step): bool
    {
        $step->started_at ??= now();

        $stale = $step->status === AgentStep::WAITING
            && $step->started_at->lt(now()->subMinutes(self::STEP_TIMEOUT));

        try {
            if ($stale) {
                throw new RuntimeException('It took longer than ' . self::STEP_TIMEOUT . ' minutes.');
            }

            $finished = $step
                ->action()
                ->advance($step);
        } catch (Throwable $e) {
            Log::error('Content agent step failed', ['run' => $step->agent_run_id, 'step' => $step->key, 'exception' => $e]);

            $step
                ->forceFill([
                    'status' => AgentStep::FAILED,
                    'error' => $this->reason($e),
                    'output' => [...($step->output ?? []), 'failure' => $this->detail($e)],
                    'finished_at' => now(),
                ])
                ->save();

            return false;
        }

        $step
            ->forceFill([
                'status' => $finished ? AgentStep::DONE : AgentStep::WAITING,
                'finished_at' => $finished ? now() : null,
            ])
            ->save();

        if ($finished) {
            $this->announce($step->run);
        }

        return true;
    }

    protected function fail(AgentRun $run, Throwable|string $error, ?AgentStep $step = null): void
    {
        if ($error instanceof Throwable) {
            Log::error('Content agent run failed', ['run' => $run->id, 'exception' => $error]);
        }

        $reason = $error instanceof Throwable ? $this->reason($error) : $error;

        $failed = AgentRun::query()
            ->whereKey($run->id)
            ->whereIn('status', AgentRun::ACTIVE)
            ->update([
                'status' => AgentRun::FAILED,
                'error' => $step ? 'Stopped at "' . ($step->input['title'] ?? $step->input['name'] ?? $step->key) . "\": {$reason}" : $reason,
                'failure' => $step?->output['failure'] ?? ($error instanceof Throwable ? $this->detail($error) : $error),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        $run->refresh();

        if ($failed) {
            $run
                ->steps()
                ->open()
                ->update(['status' => AgentStep::SKIPPED]);
        }
    }

    /**
     * Tell content admins and the owner account that a run moved on.
     */
    protected function announce(AgentRun $run): void
    {
        Realtime::send([Channels::adminContent(), Channels::user($run->owner_id)], 'agent.run', [
            'id' => $run->id,
            'owner_id' => $run->owner_id,
            'status' => $run->status,
        ]);
    }

    /**
     * What went wrong, in words fit for the run's owner. Errors from AI
     * providers and their APIs stay out of it; detail() keeps them for admins.
     */
    protected function reason(Throwable $e): string
    {
        $message = match (true) {
            $e instanceof ValidationException => collect($e->errors())
                ->flatten()
                ->first(),
            $e instanceof ReplicateException,
            $e instanceof SunoException,
            $e instanceof CloudflareException,
            $e instanceof HttpClientException => 'The AI service couldn\'t do this right now. Try again later.',
            default => $e->getMessage(),
        };

        return str((string) $message)
            ->limit(500)
            ->toString();
    }

    /**
     * The full error, for admins.
     */
    protected function detail(Throwable $e): string
    {
        $detail = $e instanceof AgentStepFailed && $e->detail
            ? $e->detail
            : class_basename($e) . ': ' . $e->getMessage();

        return str($detail)
            ->limit(2000)
            ->toString();
    }
}
