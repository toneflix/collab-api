<?php

namespace App\Traits;

use App\Helpers\Freq;
use App\Models\Recommendation;
use App\Models\Track;
use App\Models\User;
use App\Services\Reputation;
use App\Services\Streams\StreamPlays;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait Metricable
{
    use FilterByPlayPopularityTimeFrame;

    public function recommendations(): MorphMany
    {
        return $this
            ->morphMany(Recommendation::class, 'recommendable')
            ->latest();
    }

    /**
     * Scope a query to only include latest items.
     *
     *
     * @return Builder
     */
    public function scopeLatestInPeriod(Builder $query, ?string $period = 'years_2'): void
    {
        $days = Freq::period($period);

        $query->whereBetween('created_at', [now()->subDays($days), now()]);
        $query->latest();
    }

    /**
     * Scope a query to only include popular items.
     *
     *
     * @return Builder
     */
    public function scopePopular(Builder $query, ?string $filter = 'top', ?string $period = 'years_2')
    {
        $days = Freq::period($period);

        $query->popularPlayedLastDays($days);
    }

    /**
     * Scope a query to only include favorite items.
     *
     * @param  int  $limit
     * @return Builder
     */
    public function scopeFavorite(Builder $query, $limit = 10)
    {
        $user = auth()
            ->user('sanctum');

        if (! $user) {
            return $query->whereNull('id');
        }

        $query->whereHas('favorites', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        });
    }

    /**
     * Scope a query to only include featured items.
     *
     * @param  int  $limit
     * @return Builder
     */
    public function scopeFeatured(Builder $query, $limit = 10, $filter = 'featured') {}

    /**
     * Scope a query to only include related items.
     *
     * @return Builder
     */
    public function scopeIsRelated(Builder $query, $search = null) {}

    /**
     * Scope a query to only include recently_seen items.
     *
     * @param  int  $limit
     * @return Builder
     */
    public function scopeRecentlySeen(Builder $query, $limit = 10) {}

    /**
     * Scope a query to only include recommended items.
     */
    public function scopeRecommended(Builder $query, User $user): void
    {
        // Recommendations come from App\Services\Recommendation (run by `php artisan app:recommend`).
        $query->where(function (Builder $query) use ($user) {
            $query
                ->whereHas(
                    'recommendations',
                    fn(Builder $q) => $q->where('recommendations.user_id', $user->id)
                )
                ->orWhereHas('plays', fn(Builder $q) => $q->where('track_plays.user_id', $user->id))
                ->when(
                    $this instanceof Track,
                    fn(Builder $q) => $q->orWhereHas(
                        'socialInteractions',
                        fn(Builder $q) => $q
                            ->where('interactor_id', $user->id)
                            ->where('interactor_type', $user->getMorphClass())
                    )
                );
        });
    }

    /**
     * Record a view, or a play of $seconds for tracks, by a signed-in user,
     * and credit the owner's reputation.
     */
    public function stream(int|float $seconds = 0, ?User $user = null): void
    {
        if (! $user) {
            return;
        }

        if ($this instanceof Track) {
            app(StreamPlays::class)->record($this, $user, (int) $seconds);

            $owner = $this->release?->user;
            $event = $seconds > 0 ? 'track_views' : 'track_streams';
        } else {
            $owner = $this->user;
            $event = 'release_views';
        }

        if ($owner) {
            (new Reputation($owner, $event))->add();
        }
    }
}
