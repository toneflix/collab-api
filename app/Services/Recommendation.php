<?php

namespace App\Services;

use App\Enums\PeriodDays;
use App\Models\Artist;
use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds a user's recommendations from what they listen to, like and follow.
 *
 * - Tracks: unheard tracks in the user's favourite genres or by artists they listen to or follow.
 * - Artists: artists in those genres that the user doesn't follow yet.
 * - Users: people followed by the people the user follows, then people nearby.
 *
 * Results are ranked by popularity and stored in the recommendations table,
 * where the `recommended` scopes and the artist explorer pick them up.
 */
class Recommendation
{
    /**
     * How many items to recommend for each type.
     */
    public int $limit = 0;

    /**
     * How far back to look at the user's activity, in days.
     */
    public int $historyDays = 180;

    /**
     * @param  array<int,class-string>|null  $recommendables  The types to recommend. Defaults to tracks, artists and users.
     */
    public function __construct(
        public User $user,
        public ?array $recommendables = null
    ) {
        $this->recommendables ??= [Track::class, Artist::class, User::class];

        /** @var PeriodDays $expiry */
        $expiry = config('settings.recommendation_expiry', PeriodDays::DAY);

        $this->limit = max(1, (int) config('settings.max_daily_recommendation', 5) * $expiry->value);
    }

    /**
     * Replace the user's recommendations with a fresh set.
     *
     * @return int The number of recommendations created.
     */
    public function generate(): int
    {
        $items = collect();

        if (in_array(Track::class, $this->recommendables)) {
            $items = $items->merge($this->tracks());
        }

        if (in_array(Artist::class, $this->recommendables)) {
            $items = $items->merge($this->artists());
        }

        if (in_array(User::class, $this->recommendables)) {
            $items = $items->merge($this->users());
        }

        DB::transaction(function () use ($items) {
            $this->user->recommendations()->delete();
            $this->user->recommendations()->createMany($items->all());
        });

        return $items->count();
    }

    /**
     * IDs of tracks the user streamed or liked recently.
     */
    public function seedTrackIds(): Collection
    {
        $since = now()->subDays($this->historyDays);

        $streamed = DB::table('laravisits')
            ->where('visitable_type', (new Track)->getMorphClass())
            ->where('data->user', $this->user->id)
            ->where('created_at', '>=', $since)
            ->pluck('visitable_id');

        $liked = DB::table(config('social-interactions.tables.interactions', 'social_interactions'))
            ->where('interactable_type', (new Track)->getMorphClass())
            ->where('interactor_type', $this->user->getMorphClass())
            ->where('interactor_id', $this->user->id)
            ->where('liked', true)
            ->pluck('interactable_id');

        return $streamed->merge($liked)->map(fn ($id) => (int) $id)->unique()->values();
    }

    /**
     * Genre IDs the user listens to most, most played first.
     */
    public function seedGenreIds(Collection $trackIds): Collection
    {
        return Track::whereIn('id', $trackIds)
            ->whereNotNull('genre_id')
            ->pluck('genre_id')
            ->countBy()
            ->sortDesc()
            ->keys()
            ->take(5)
            ->values();
    }

    /**
     * Artists the user listens to or follows.
     */
    public function seedArtistIds(Collection $trackIds): Collection
    {
        $listened = DB::table('track_artist')->whereIn('track_id', $trackIds)->pluck('artist_id');

        return $listened->merge($this->followedIds(Artist::class))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * @param  class-string  $type
     */
    protected function followedIds(string $type): Collection
    {
        return $this->user->followings()
            ->where('followable_type', (new $type)->getMorphClass())
            ->pluck('followable_id');
    }

    /**
     * Recommend tracks the user hasn't heard yet.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function tracks(): Collection
    {
        $heard = $this->seedTrackIds();
        $genres = $this->seedGenreIds($heard);
        $artists = $this->seedArtistIds($heard);

        if ($genres->isEmpty() && $artists->isEmpty()) {
            return collect();
        }

        return Track::query()
            ->with('genre')
            ->whereNotIn('id', $heard)
            ->whereHas('release', fn (Builder $q) => $q->live())
            ->where(function (Builder $q) use ($genres, $artists) {
                $q->whereIn('genre_id', $genres)
                    ->orWhereHas('artists', fn (Builder $q) => $q->whereIn('artists.id', $artists));
            })
            ->whereDoesntHave('release', fn (Builder $q) => $q->where('user_id', $this->user->id))
            ->withCount(['visits as plays' => fn (Builder $q) => $q->where('data->duration', '>', 0)])
            ->orderByDesc('plays')
            ->inRandomOrder()
            ->limit($this->limit)
            ->get()
            ->toBase()
            ->map(fn (Track $track) => $this->item($track, $genres->contains($track->genre_id)
                ? 'genre:'.$track->genre?->slug
                : 'artist'));
    }

    /**
     * Recommend artists in the user's genres that they don't follow yet.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function artists(): Collection
    {
        $heard = $this->seedTrackIds();
        $genres = $this->seedGenreIds($heard);

        if ($genres->isEmpty()) {
            return collect();
        }

        return Artist::query()
            ->whereNotIn('id', $this->followedIds(Artist::class))
            ->where(fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $this->user->id))
            ->whereHas('tracks', fn (Builder $q) => $q->whereIn('genre_id', $genres))
            ->withCount('followers')
            ->orderByDesc('followers_count')
            ->inRandomOrder()
            ->limit($this->limit)
            ->get()
            ->toBase()
            ->map(fn (Artist $artist) => $this->item($artist, 'genre'));
    }

    /**
     * Recommend people followed by the people the user follows, then people nearby.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function users(): Collection
    {
        $following = $this->followedIds(User::class)->push($this->user->id);
        $morph = $this->user->getMorphClass();

        $friendsOfFriends = User::query()
            ->whereNotIn('id', $following)
            ->whereHas('followers', fn (Builder $q) => $q->whereIn('followables.user_id', $following))
            ->withCount('followers')
            ->orderByDesc('followers_count')
            ->limit($this->limit)
            ->get()
            ->toBase()
            ->map(fn (User $user) => $this->item($user, 'network'));

        $remaining = $this->limit - $friendsOfFriends->count();

        if ($remaining < 1 || ! ($this->user->city || $this->user->state || $this->user->country)) {
            return $friendsOfFriends;
        }

        $nearby = User::query()
            ->whereNotIn('id', $following->merge($friendsOfFriends->pluck('recommendable_id')))
            ->where(function (Builder $q) {
                foreach (['city', 'state', 'country'] as $field) {
                    if ($this->user->{$field}) {
                        $q->orWhere($field, $this->user->{$field});
                    }
                }
            })
            ->inRandomOrder()
            ->limit($remaining)
            ->get()
            ->toBase()
            ->map(fn (User $user) => $this->item($user, 'location'));

        return $friendsOfFriends->merge($nearby);
    }

    /**
     * @return array<string, mixed>
     */
    protected function item(Track|Artist|User $model, string $reason): array
    {
        return [
            'keywords' => $reason,
            'recommendable_id' => $model->id,
            'recommendable_type' => $model->getMorphClass(),
        ];
    }
}
