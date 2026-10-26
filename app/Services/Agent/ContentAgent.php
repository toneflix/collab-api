<?php

namespace App\Services\Agent;

use App\Enums\Feature;
use App\Jobs\AdvanceAgentRun;
use App\Models\AgentRun;
use App\Models\AgentStep;
use App\Models\User;
use App\Services\Ai\Credits;
use App\Services\Limits;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * The content agent: admins and the users of agent accounts queue prompts,
 * and runs are planned and built one at a time, in the order they were
 * queued, on the agent account that owns them.
 */
class ContentAgent
{
    /** Seconds one pass over a run may keep working before it hands over */
    public const PASS_SECONDS = 90;

    /** Seconds between passes while a run waits on queued work */
    public const WAIT_SECONDS = 15;

    /** Minutes a step may wait on queued work before it fails */
    public const STEP_TIMEOUT = 60;

    public function __construct(protected AgentPlanner $planner) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function queue(User $by, User $owner, string $prompt, array $options): AgentRun
    {
        if (! $owner->content_agent) {
            throw ValidationException::withMessages(['owner_id' => 'This account isn\'t an agent account.']);
        }

        Credits::for($owner)->ensureAccess('owner_id');

        $run = AgentRun::query()->create([
            'user_id' => $by->id,
            'owner_id' => $owner->id,
            'prompt' => trim($prompt),
            'options' => $options,
        ]);

        $this->next();

        return $run->refresh();
    }

    /**
     * Start the oldest queued run when no other run is being planned or built.
     */
    public function next(): ?AgentRun
    {
        $run = DB::transaction(function () {
            $busy = AgentRun::query()
                ->active()
                ->lockForUpdate()
                ->exists();

            if ($busy) {
                return null;
            }

            $run = AgentRun::query()
                ->where('status', AgentRun::QUEUED)
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

        if ($run->isActive()) {
            AdvanceAgentRun::dispatch($run)
                ->delay(now()->addSeconds(self::WAIT_SECONDS));
        } else {
            $this->next();
        }
    }

    /**
     * Stop a run. Steps not started are skipped; AI work already started
     * finishes on the owner's tracks.
     */
    public function cancel(AgentRun $run): AgentRun
    {
        if ($run->isFinished()) {
            return $run;
        }

        $run
            ->steps()
            ->open()
            ->update(['status' => AgentStep::SKIPPED]);

        $run
            ->forceFill(['status' => AgentRun::CANCELLED, 'finished_at' => now()])
            ->save();

        $this->next();

        return $run;
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

        $run
            ->steps()
            ->where('status', AgentStep::SKIPPED)
            ->update(['status' => AgentStep::PENDING]);

        $run
            ->forceFill(['status' => AgentRun::QUEUED, 'error' => null, 'failure' => null, 'finished_at' => null])
            ->save();

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
     * @param  array{artists: list<array<string, mixed>>, albums: list<array<string, mixed>>, songs: list<array<string, mixed>>}  $plan
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

        if ($projects > 0 && $hasProjects + $newProjects > $projects) {
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

        if ($songs > 0 && $hasSongs + $newSongs > $songs) {
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

        $status = AgentRun::query()
            ->whereKey($run->id)
            ->value('status');

        if ($status !== AgentRun::RUNNING) {
            $run->status = $status;

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
                ->forceFill(['status' => AgentStep::FAILED, 'error' => $this->reason($e), 'finished_at' => now()])
                ->save();

            return false;
        }

        $step
            ->forceFill([
                'status' => $finished ? AgentStep::DONE : AgentStep::WAITING,
                'finished_at' => $finished ? now() : null,
            ])
            ->save();

        return true;
    }

    protected function fail(AgentRun $run, Throwable|string $error, ?AgentStep $step = null): void
    {
        if ($error instanceof Throwable) {
            Log::error('Content agent run failed', ['run' => $run->id, 'exception' => $error]);
        }

        $reason = $error instanceof Throwable ? $this->reason($error) : $error;

        $run
            ->steps()
            ->open()
            ->update(['status' => AgentStep::SKIPPED]);

        $run
            ->forceFill([
                'status' => AgentRun::FAILED,
                'error' => $step ? 'Stopped at "' . ($step->input['title'] ?? $step->input['name'] ?? $step->key) . "\": {$reason}" : $reason,
                'failure' => $error instanceof Throwable ? class_basename($error) . ': ' . $error->getMessage() : $error,
                'finished_at' => now(),
            ])
            ->save();
    }

    protected function reason(Throwable $e): string
    {
        $message = $e instanceof ValidationException
            ? collect($e->errors())
                ->flatten()
                ->first()
            : $e->getMessage();

        return str((string) $message)
            ->limit(500)
            ->toString();
    }
}
