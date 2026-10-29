<?php

namespace App\Services\Playlists;

use App\Models\Track;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Tracks to play once the player's queue runs out, so playback never stops.
 *
 * Only playable tracks from live releases are picked: related tracks first,
 * then ones from the seed's album, artists and label, then ones matching the
 * listener's interests, then the rest. When the exclusions leave
 * nothing, they are dropped, so the only track that is never returned is
 * the seed.
 */
class UpNext
{
    public const MAX_EXCLUDED = 100;

    public const MAX_PER_PAGE = 50;

    /**
     * How far a tempo may be from the seed's, as a share of it, to count as close.
     */
    public const TEMPO_RANGE = 0.08;

    /**
     * @var Collection<int, int>
     */
    public Collection $excluded;

    /**
     * @param  iterable<int|string>  $exclude  IDs of tracks already queued.
     */
    public function __construct(
        public ?User $user = null,
        public ?Track $seed = null,
        iterable $exclude = [],
        public bool $randomize = false,
    ) {
        $this->excluded = collect($exclude)
            ->map(fn($id) => (int) $id)
            ->filter()
            ->unique()
            ->take(self::MAX_EXCLUDED)
            ->values();
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $page = $this
            ->query($this->excluded)
            ->paginate($perPage);

        if ($page->isEmpty() && $page->currentPage() === 1 && $this->excluded->isNotEmpty()) {
            return $this
                ->query(collect())
                ->paginate($perPage);
        }

        return $page;
    }

    /**
     * @param  Collection<int, int>  $exclude
     */
    public function query(Collection $exclude): Builder
    {
        if ($this->seed) {
            $exclude = $exclude
                ->merge([$this->seed->id])
                ->unique();
        }

        $query = Track::query()
            ->with('release')
            ->whereHas('release', fn(Builder $q) => $q->live())
            ->playableBy($this->user)
            ->when($exclude->isNotEmpty(), fn(Builder $q) => $q->whereNotIn('tracks.id', $exclude->all()));

        $this->rank($query);

        return $this->randomize
            ? $query->inRandomOrder()
            : $query
                ->orderByDesc('streams')
                ->orderByDesc('tracks.id');
    }

    /**
     * Order tracks by tier: related to the seed, then from its album, its
     * artists, its label, the listener's interests, and finally everything else.
     */
    protected function rank(Builder $query): void
    {
        $tiers = collect([
            $this->related(),
            $this->seed?->release_id ? ['tracks.release_id = ?', [$this->seed->release_id]] : null,
            $this->sameArtists(),
            $this->seed?->release?->record_label
                ? ['exists (select 1 from releases where releases.id = tracks.release_id and releases.record_label = ?)', [$this->seed->release->record_label]]
                : null,
            $this->user
                ? [Track::interestsSql(), Track::interestsBindings($this->user)]
                : null,
        ])
            ->filter()
            ->values();

        if ($tiers->isEmpty()) {
            return;
        }

        $cases = $tiers
            ->map(fn(array $tier, int $rank) => "when {$tier[0]} then {$rank}")
            ->implode(' ');

        $bindings = $tiers
            ->flatMap(fn(array $tier) => $tier[1])
            ->all();

        $query->orderByRaw("case {$cases} else {$tiers->count()} end", $bindings);
    }

    /**
     * Tracks recommended to the listener, or in the seed's genre at a close tempo.
     *
     * @return array{0: string, 1: list<mixed>}|null
     */
    protected function related(): ?array
    {
        $conditions = [];
        $bindings = [];

        if ($this->seed?->genre_id && $this->seed->bpm) {
            $conditions[] = '(tracks.genre_id = ? and tracks.bpm between ? and ?)';
            array_push(
                $bindings,
                $this->seed->genre_id,
                $this->seed->bpm * (1 - self::TEMPO_RANGE),
                $this->seed->bpm * (1 + self::TEMPO_RANGE),
            );
        } elseif ($this->seed?->genre_id) {
            $conditions[] = 'tracks.genre_id = ?';
            $bindings[] = $this->seed->genre_id;
        }

        if ($this->user) {
            $conditions[] = 'exists (select 1 from recommendations where recommendations.recommendable_id = tracks.id and recommendations.recommendable_type = ? and recommendations.user_id = ?)';
            array_push($bindings, (new Track)->getMorphClass(), $this->user->id);
        }

        return $conditions ? ['(' . implode(' or ', $conditions) . ')', $bindings] : null;
    }

    /**
     * Tracks sharing an artist with the seed.
     *
     * @return array{0: string, 1: list<int>}|null
     */
    protected function sameArtists(): ?array
    {
        $artists = $this->seed
            ?->artists()
            ->pluck('artists.id') ?? collect();

        if ($artists->isEmpty()) {
            return null;
        }

        $marks = $artists
            ->map(fn() => '?')
            ->implode(', ');

        return ["exists (select 1 from track_artist where track_artist.track_id = tracks.id and track_artist.artist_id in ({$marks}))", $artists->all()];
    }
}
