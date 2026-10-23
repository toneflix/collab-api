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
        'billing_options',
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
            'billing_options' => 'array',
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
     * How long a payment lasts, e.g. "1 month", or "6 month" when paying for 6 cycles at once.
     */
    public function period(int $cycles = 1): string
    {
        return max(1, $this->interval_count ?: 1) * max(1, $cycles).' '.($this->interval ?: 'month');
    }

    /**
     * The ways this plan can be paid for: one cycle at full price, then any bulk
     * options, e.g. 6 or 12 cycles at a discount.
     *
     * @return list<array{cycles: int, discount: float, price: float, perCycle: float, saved: float}>
     */
    public function billingOptions(): array
    {
        return collect([['cycles' => 1, 'discount' => 0], ...($this->billing_options ?? [])])
            ->map(fn ($option) => [
                'cycles' => max(1, (int) ($option['cycles'] ?? 1)),
                'discount' => min(100, max(0, round((float) ($option['discount'] ?? 0), 2))),
            ])
            ->unique('cycles')
            ->sortBy('cycles')
            ->map(function ($option) {
                $full = $this->price * $option['cycles'];
                $price = round($full * (1 - $option['discount'] / 100), 2);

                return [
                    ...$option,
                    'price' => $price,
                    'perCycle' => round($price / $option['cycles'], 2),
                    'saved' => round($full - $price, 2),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The billing option for a number of cycles, or null when the plan doesn't offer it.
     *
     * @return array{cycles: int, discount: float, price: float, perCycle: float, saved: float}|null
     */
    public function billingOption(int $cycles): ?array
    {
        return collect($this->billingOptions())->firstWhere('cycles', $cycles);
    }

    public function isFree(): bool
    {
        return $this->price <= 0;
    }
}
