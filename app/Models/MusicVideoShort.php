<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A vertical clip cut from a finished music video, for short video feeds.
 */
class MusicVideoShort extends Model
{
    public const CUTTING = 'cutting';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const MIN_SECONDS = 5;

    public const MAX_SECONDS = 60;

    /** Shorts one video keeps */
    public const MAX = 20;

    /** Width and height of a short */
    public const SIZE = [720, 1280];

    protected $attributes = [
        'status' => self::CUTTING,
        'focus' => 0.5,
    ];

    protected $fillable = [
        'music_video_id',
        'status',
        'start',
        'end',
        'focus',
        'file',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'start' => 'float',
            'end' => 'float',
            'focus' => 'float',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(MusicVideo::class, 'music_video_id');
    }

    public function length(): float
    {
        return round($this->end - $this->start, 2);
    }
}
