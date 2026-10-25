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
 * Only public, playable tracks from live releases are picked. Tracks like the seed (same genre,
 * artists or tempo) come first, then the listener's recommendations, then
 * the rest. When the exclusions leave nothing, they are dropped, so the only
 * track that is never returned is the seed.
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

        $page = $this->query($this->excluded)->paginate($perPage);

        if ($page->isEmpty() && $page->currentPage() === 1 && $this->excluded->isNotEmpty()) {
            return $this->query(collect())->paginate($perPage);
        }

        return $page;
    }

    /**
     * @param  Collection<int, int>  $exclude
     */
    public function query(Collection $exclude): Builder
    {
        $exclude = $this->seed ? $exclude->merge([$this->seed->id])->unique() : $exclude;

        $query = Track::query()
            ->where('tracks.privacy', 'public')
            ->whereHas('release', fn(Builder $q) => $q->live())
            ->playableBy($this->user)
            ->when($exclude->isNotEmpty(), fn(Builder $q) => $q->whereNotIn('tracks.id', $exclude->all()));

        $this->rank($query);

        return $this->randomize
            ? $query->inRandomOrder()
            : $query->orderByDesc('streams')->orderByDesc('tracks.id');
    }

    /**
     * Order tracks by how well they follow the seed and suit the listener.
     */
    protected function rank(Builder $query): void
    {
        $scores = [];
        $bindings = [];

        if ($this->seed?->genre_id) {
            $scores[] = 'case when tracks.genre_id = ? then 3 else 0 end';
            $bindings[] = $this->seed->genre_id;
        }

        $artists = $this->seed?->artists()->pluck('artists.id') ?? collect();

        if ($artists->isNotEmpty()) {
            $marks = $artists->map(fn() => '?')->implode(', ');
            $scores[] = "case when exists (select 1 from track_artist where track_artist.track_id = tracks.id and track_artist.artist_id in ({$marks})) then 3 else 0 end";
            array_push($bindings, ...$artists->all());
        }

        if ($this->seed?->bpm) {
            $scores[] = 'case when tracks.bpm between ? and ? then 1 else 0 end';
            array_push(
                $bindings,
                $this->seed->bpm * (1 - self::TEMPO_RANGE),
                $this->seed->bpm * (1 + self::TEMPO_RANGE),
            );
        }

        if ($this->user) {
            $scores[] = 'case when exists (select 1 from recommendations where recommendations.recommendable_id = tracks.id and recommendations.recommendable_type = ? and recommendations.user_id = ?) then 2 else 0 end';
            array_push($bindings, (new Track)->getMorphClass(), $this->user->id);
        }

        if ($scores) {
            $query->orderByRaw('(' . implode(' + ', $scores) . ') desc', $bindings);
        }
    }
}
