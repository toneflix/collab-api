<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Recommendation extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'keywords',
        'recommendable_id',
        'recommendable_type',
    ];

    /**
     * Get the Recommendation's recommendable model
     */
    public function recommendable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the user that owns the Artist
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
