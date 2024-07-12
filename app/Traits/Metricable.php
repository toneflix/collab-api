<?php

namespace App\Traits;

use App\Helpers\Freq;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use App\Services\Reputation;
use Coderflex\Laravisit\Concerns\HasVisits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait Metricable
{
    use FilterByPlayPopularityTimeFrame;
    use HasVisits;

    public function recommendations(): MorphMany
    {
        return $this->morphMany(Recommendation::class, 'recommendable')->latest();
    }

    /**
     * Scope a query to only include latest items.
     *
     *
     * @return \Illuminate\Database\Eloquent\Builder
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
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePopular(Builder $query, ?string $filter = 'top', ?string $period = 'years_2')
    {
        $days = Freq::period($period);

        if ($this instanceof Release) {
            $query->popularLastDays($days);
        } elseif ($this instanceof Track) {
            $query->popularPlayedLastDays($days);
        } else {
            $query->popularLastDays($days);
        }
    }

    /**
     * Scope a query to only include favorite items.
     *
     * @param  int  $limit
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFavorite(Builder $query, $limit = 10)
    {
        $user = auth()->user('sanctum');

        if (!$user) {
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
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFeatured(Builder $query, $limit = 10, $filter = 'featured')
    {
    }

    /**
     * Scope a query to only include related items.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeIsRelated(Builder $query, $search = null)
    {
    }

    /**
     * Scope a query to only include recently_seen items.
     *
     * @param  int  $limit
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecentlySeen(Builder $query, $limit = 10)
    {
    }

    /**
     * Scope a query to only include recommended items.
     *
     * @param  int  $limit
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecommended(Builder $query, $limit = 10)
    {
        // TODO: Implement scopeRecommended() method.
        return $query->inRandomOrder()->limit($limit);
    }

    /**
     * Let's try to recommend items based on the user's visit history.
     */
    protected function recommendationEngine()
    {
        // TODO: Implement recommendationEngine() method.
    }

    /**
     * Record a new stream for the model.
     */
    public function stream($duration = 0, User $user = null): void
    {
        if (!$user) {
            return;
        }

        $this->visits()
            ->when($duration > 0, fn ($q) => $q->where('created_at', '>=', now()->subSeconds($this->duration ?? 0)))
            ->updateOrCreate([
                'data->user' => $user->id,
            ], [
                'data' => collect([
                    'user_agent' => request(null)->userAgent(),
                    'user' => $user->id,
                    'ip' => request(null)->ip(),
                ])->when($duration > 0, fn ($c) => $c->merge(['duration' => $duration]))->toArray(),
            ]);

        // Compute the reputation owner and type
        if ($this instanceof Track) {
            $owner = $this->release->user;
            $event = $duration > 0 ? 'track_views' : 'track_streams';
        } else {
            $owner = $this->user;
            $event = 'release_views';
        }

        // Add reputation for the owner
        (new Reputation($owner, $event))->add();
    }
}
