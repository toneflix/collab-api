<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A short, silent, looping vertical clip that plays behind a released track
 * in the player, made by AI from the track's artwork or uploaded by its owner.
 */
class TrackCanvas extends Model
{
    /** AI is drawing and animating it */
    public const DRAWING = 'drawing';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const AI = 'ai';

    public const UPLOAD = 'upload';

    /** Size every canvas is stored at, 9:16 */
    public const SIZE = [720, 1280];

    /** Shortest and longest loop, in seconds */
    public const MIN_SECONDS = 3;

    public const MAX_SECONDS = 8;

    protected $attributes = [
        'status' => self::DRAWING,
        'source' => self::AI,
    ];

    protected $fillable = [
        'user_id',
        'track_id',
        'status',
        'source',
        'brief',
        'file',
        'poster',
        'duration',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'duration' => 'float',
        ];
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeReady(Builder $query): void
    {
        $query
            ->where('status', self::READY)
            ->whereNotNull('file');
    }
}
