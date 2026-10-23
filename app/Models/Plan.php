<?php

namespace App\Models;

use App\Enums\Feature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

/**
 * A subscription plan. Its features say what it grants (toggles) and how far
 * it goes (limits). See App\Enums\Feature for the catalogue.
 */
class Plan extends Model
{
    public const INTERVALS = ['day', 'week', 'month', 'year'];

    protected $attributes = [
        'price' => 0,
        'interval' => 'month',
        'interval_count' => 1,
        'is_default' => false,
        'active' => true,
        'sort' => 0,
    ];

    protected $fillable = [
        'slug',
        'name',
        'description',
        'price',
        'interval',
        'interval_count',
        'features',
        'is_default',
        'active',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'interval_count' => 'integer',
            'features' => 'array',
            'is_default' => 'boolean',
            'active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('plans::default'));
        static::deleted(fn () => Cache::forget('plans::default'));
    }

    /**
     * The plan for accounts without an active subscription. When none is
     * marked as default, every feature falls back to its catalogue default.
     */
    public static function default(): self
    {
        $plan = Cache::remember('plans::default', 3600, fn () => static::where('is_default', true)->first());

        return $plan ?? new self(['slug' => 'free', 'name' => 'Free', 'price' => 0, 'features' => [], 'is_default' => true]);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('price')->orderBy('id');
    }

    /**
     * What this plan gives for a feature, or the catalogue default when it doesn't say.
     */
    public function feature(Feature $feature): bool|int
    {
        $features = $this->features ?? [];

        return array_key_exists($feature->value, $features)
            ? $feature->cast($features[$feature->value])
            : $feature->fallback();
    }

    /**
     * Every catalogue feature with this plan's value.
     *
     * @return array<string, bool|int>
     */
    public function allFeatures(): array
    {
        return collect(Feature::cases())->mapWithKeys(fn (Feature $f) => [$f->value => $this->feature($f)])->all();
    }

    /**
     * How long one payment lasts, e.g. "1 month".
     */
    public function period(): string
    {
        return max(1, $this->interval_count ?: 1).' '.($this->interval ?: 'month');
    }

    public function isFree(): bool
    {
        return $this->price <= 0;
    }
}
