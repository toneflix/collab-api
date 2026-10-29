<?php

namespace App\Services\Agent;

use App\Models\AgentRun;
use App\Models\AgentStep;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes a part out of a run's plan, like an album, one of its tracks, a
 * release, a touch-up or a written song. Its unfinished steps go, and the
 * steps left stop waiting on them; what it already made stays.
 */
class AgentRunParts
{
    /** Kinds of part, as written in "kind:key" */
    public const KINDS = ['album', 'track', 'release', 'update', 'song'];

    public function remove(AgentRun $run, string $part): void
    {
        [$kind, $key] = array_pad(explode(':', $part, 2), 2, '');
        $plan = $run->plan ?? [];

        if (! in_array($kind, self::KINDS, true) || $key === '' || ! $this->has($plan, $kind, $key)) {
            throw ValidationException::withMessages(['part' => 'That isn\'t part of this run.']);
        }

        [$plan, $parts] = $this->without($plan, $kind, $key);

        DB::transaction(function () use ($run, $plan, $parts) {
            $steps = $run
                ->steps()
                ->orderBy('position')
                ->get();

            $removed = $steps
                ->filter(fn(AgentStep $step) => $step->status !== AgentStep::DONE && $this->belongs($step->key, $parts))
                ->pluck('key');

            $removed = $this->dropEmptyReleases($steps, $removed, $parts);

            $run
                ->steps()
                ->whereIn('key', $removed)
                ->delete();

            $steps
                ->reject(fn(AgentStep $step) => $removed->contains($step->key))
                ->each(fn(AgentStep $step) => $this->unlink($step, $removed, $parts));

            $run
                ->forceFill(['plan' => $plan])
                ->save();
        });
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    protected function has(array $plan, string $kind, string $key): bool
    {
        if ($kind === 'track') {
            return collect($plan['albums'] ?? [])
                ->flatMap(fn(array $album) => $album['tracks'] ?? [])
                ->contains('key', $key);
        }

        return collect($plan[$this->list($kind)] ?? [])->contains('key', $key);
    }

    /**
     * The plan without the part, and every part that goes with it: an album
     * takes its tracks, and a track that was its album's last takes the album.
     *
     * @param  array<string, mixed>  $plan
     * @return array{0: array<string, mixed>, 1: list<array{0: string, 1: string}>}
     */
    protected function without(array $plan, string $kind, string $key): array
    {
        if ($kind === 'track') {
            foreach ($plan['albums'] as $i => $album) {
                $tracks = array_values(array_filter($album['tracks'], fn(array $track) => $track['key'] !== $key));

                if (count($tracks) === count($album['tracks'])) {
                    continue;
                }

                if (! $tracks) {
                    return $this->without($plan, 'album', $album['key']);
                }

                $plan['albums'][$i]['tracks'] = $tracks;
            }

            return [$plan, [['track', $key]]];
        }

        $list = $this->list($kind);
        $item = collect($plan[$list])->firstWhere('key', $key);
        $plan[$list] = array_values(array_filter($plan[$list], fn(array $entry) => $entry['key'] !== $key));

        $parts = [[$kind, $key]];

        foreach ($kind === 'album' ? $item['tracks'] : [] as $track) {
            $parts[] = ['track', $track['key']];
        }

        return [$plan, $parts];
    }

    protected function list(string $kind): string
    {
        return match ($kind) {
            'album' => 'albums',
            'release' => 'releases',
            'update' => 'updates',
            default => 'songs',
        };
    }

    /**
     * Whether a step key is one of the parts' steps.
     *
     * @param  list<array{0: string, 1: string}>  $parts
     */
    protected function belongs(string $stepKey, array $parts): bool
    {
        foreach ($parts as [$kind, $key]) {
            $keys = match ($kind) {
                'album' => ["project:{$key}", "cover:{$key}", "release:{$key}"],
                'track' => ["song:{$key}", "track:{$key}", "music:{$key}", "mix:{$key}", "lyrics:{$key}"],
                'release' => ["release:{$key}", "cover:{$key}"],
                'update' => ["cover:{$key}", "describe:{$key}"],
                default => ["song:{$key}"],
            };

            $prefix = match ($kind) {
                'release' => "mix:{$key}-",
                'update' => "lyrics:{$key}-",
                default => null,
            };

            if (in_array($stepKey, $keys, true) || ($prefix && str_starts_with($stepKey, $prefix))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add the unfinished releases that would have no tracks left.
     *
     * @param  Collection<int, AgentStep>  $steps
     * @param  Collection<int, string>  $removed
     * @param  list<array{0: string, 1: string}>  $parts
     * @return Collection<int, string>
     */
    protected function dropEmptyReleases(Collection $steps, Collection $removed, array $parts): Collection
    {
        $empty = $steps
            ->filter(fn(AgentStep $step) => $step->kind === 'release' && $step->status !== AgentStep::DONE)
            ->reject(fn(AgentStep $step) => $removed->contains($step->key))
            ->filter(fn(AgentStep $step) => ! $this->releaseTracks($step, $parts) && empty($step->input['track_ids']))
            ->pluck('key');

        return $removed
            ->merge($empty)
            ->values();
    }

    /**
     * A release step's run-made tracks that are still in the plan.
     *
     * @param  list<array{0: string, 1: string}>  $parts
     * @return list<string>
     */
    protected function releaseTracks(AgentStep $step, array $parts): array
    {
        $gone = collect($parts)
            ->filter(fn(array $part) => $part[0] === 'track')
            ->flatMap(fn(array $part) => ["song:{$part[1]}", "track:{$part[1]}"]);

        return array_values(array_filter(
            $step->input['tracks'] ?? [],
            fn(string $source) => ! $gone->contains($source),
        ));
    }

    /**
     * Stop a step waiting on removed steps, and take removed tracks off a
     * release.
     *
     * @param  Collection<int, string>  $removed
     * @param  list<array{0: string, 1: string}>  $parts
     */
    protected function unlink(AgentStep $step, Collection $removed, array $parts): void
    {
        $step->depends_on = array_values(array_diff($step->depends_on ?? [], $removed->all()));

        if ($step->kind === 'release' && isset($step->input['tracks'])) {
            $step->input = [...$step->input, 'tracks' => $this->releaseTracks($step, $parts)];
        }

        $step->save();
    }
}
