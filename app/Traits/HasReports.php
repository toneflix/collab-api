<?php

namespace App\Traits;

use App\Models\Safety;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasReports
{
    public function reports(): MorphMany
    {
        return $this->morphMany(Safety::class, 'model');
    }
}
