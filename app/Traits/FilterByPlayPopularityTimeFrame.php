<?php

namespace App\Traits;

use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Filter By Play Popularity Time Frame
 */
trait FilterByPlayPopularityTimeFrame
{
    protected ?string $userThatPlayedId = null;

    /**
     * Get the total visit count
     *
     * @param Builder $builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithTotalPlaysCount(Builder $builder): Builder
    {
        return $builder->withCount([
            'visits as visit_count_total' => fn (Builder $q) => $q
                ->where('data->duration', '>', 0)
                ->when($this->userThatPlayedId, fn (Builder $q) => $q->where('data->user', $this->userThatPlayedId))
        ]);
    }

    /**
     * Get the popular visits all time
     *
     * @param Builder $builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePopularPlayedAllTime(Builder $builder): Builder
    {
        return $builder->withTotalPlaysCount()
            ->orderBy('visit_count_total', 'desc');
    }

    /**
     * Get the popular visits today
     *
     * @param Builder $builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePopularPlayedToday(Builder $builder): Builder
    {
        return $builder->popularPlayedBetween(
            now()->startOfDay(),
            now()->endOfDay()
        );
    }

    /**
     * Get the popular visits last given days
     *
     * @param Builder $builder
     * @param int $days
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePopularPlayedLastDays(Builder $builder, int $days): Builder
    {
        return $builder->popularBetween(
            now()->subDays($days),
            now()
        );
    }

    /**
     * Get the popular visits this week
     *
     * @param Builder $builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePopularPlayedThisWeek(Builder $builder): Builder
    {
        return $builder->popularPlayedBetween(
            now()->startOfWeek(),
            now()->endOfWeek(),
        );
    }

    /**
     * Get the popular visits last week
     *
     * @param Builder $builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePopularPlayedLastWeek(Builder $builder): Builder
    {
        return $builder->popularPlayedBetween(
            $startOfLastWeek = now()->subDay(7)->startOfWeek(),
            $startOfLastWeek->copy()->endOfWeek()
        );
    }

    /**
     * Get the popular visits this month
     *
     * @param Builder $builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePopularPlayedThisMonth(Builder $builder): Builder
    {
        return $builder->popularBetween(
            now()->startOfMonth(),
            now()->endOfMonth(),
        );
    }

    /**
     * Get the popular visits last month
     *
     * @param Builder $builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePopularPlayedLastMonth(Builder $builder): Builder
    {
        return $builder->popularBetween(
            now()->startOfMonth()->subMonthWithoutOverflow(),
            now()->subMonthWithoutOverflow()->endOfMonth()
        );
    }

    /**
     * Get the popular visits this year
     *
     * @param Builder $builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePopularPlayedThisYear(Builder $builder): Builder
    {
        return $builder->popularBetween(
            now()->startOfYear(),
            now()->endOfYear(),
        );
    }

    /**
     * Get the popular visits last year
     *
     * @param Builder $builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePopularPlayedLastYear(Builder $builder): Builder
    {
        return $builder->popularBetween(
            now()->startOfYear()->subYearWithoutOverflow(),
            now()->subYearWithoutOverflow()->endOfYear()
        );
    }

    /**
     * Get the popular visits between two dates
     *
     * @param Builder $builder
     * @param Carbon $from
     * @param Carbon $to
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePopularPlayedBetween(Builder $builder, Carbon $from, Carbon $to): Builder
    {
        return $builder->whereHas('visits', $this->betweenPlayedScope($from, $to))
            ->withCount([
                'visits as visit_count_total' => $this->betweenPlayedScope($from, $to),
            ]);
    }

    public function scopeUserThatPlayed(Builder $builder, Model|string $user): void
    {
        $this->userThatPlayedId = is_string($user) ? $user : $user->id;
    }

    /**
     * Get the popular visits between two dates
     *
     * @param Carbon $from
     * @param Carbon $to
     * @return Closure
     */
    protected function betweenPlayedScope(Carbon $from, Carbon $to): Closure
    {
        return fn ($query) => $query
            ->when($this->userThatPlayedId, fn (Builder $q) => $q->where('data->user', $this->userThatPlayedId))
            ->where('data->duration', '>', 0)
            ->whereBetween('created_at', [$from, $to]);
    }
}
