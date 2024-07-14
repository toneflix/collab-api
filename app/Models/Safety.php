<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Safety extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'model_type',
        'model_id',
        'type',
        'reason',
    ];

    public static $allowedReasons = [
        "dmca",
        "spam",
        "hate",
        "abuse",
        "violent",
        "privacy",
        "sensitive",
        "self-harm",
        "copyright",
        "child-safety",
    ];

    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeForItem(Builder $query, Model $model)
    {
        $query
            ->whereModelType($model->getMorphClass())
            ->whereModelId($model->getKey());
    }

    /**
     * Get the user that made the repport
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
