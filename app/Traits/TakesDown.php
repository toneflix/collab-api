<?php

namespace App\Traits;

use App\Models\Scopes\HideTakenDown;
use App\Models\Takedown;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $taken_down_at
 */
trait TakesDown
{
    use HasReports;

    public static function bootTakesDown(): void
    {
        static::addGlobalScope(new HideTakenDown);
    }

    public function initializeTakesDown(): void
    {
        $this->mergeCasts(['taken_down_at' => 'datetime']);
    }

    public function takedowns(): MorphMany
    {
        return $this->morphMany(Takedown::class, 'model');
    }

    public function isTakenDown(): bool
    {
        return $this->taken_down_at !== null;
    }
}
