<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A group of countries that pays through its own providers, in its own currency.
 */
class PaymentRegion extends Model
{
    protected $fillable = [
        'name',
        'countries',
        'providers',
        'currency',
        'exchange_rate',
        'rate_markup',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'countries' => 'array',
            'providers' => 'array',
            'exchange_rate' => 'float',
            'rate_markup' => 'float',
            'active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Regions that list any of `$countries`.
     *
     * @param  array<int, string>  $countries
     */
    public function scopeCovering(Builder $query, array $countries): Builder
    {
        return $query->where(function (Builder $q) use ($countries) {
            foreach ($countries as $country) {
                $q->orWhereJsonContains('countries', strtoupper($country));
            }
        });
    }
}
