<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A clip made for a shot. A shot plays one take, and keeps its earlier ones
 * so the creator can switch back without paying again.
 */
class MusicVideoTake extends Model
{
    /** Rendered by a video model from the shot's still */
    public const AI = 'ai';

    /** The creator's own clip */
    public const UPLOAD = 'upload';

    /** Earlier takes a shot keeps beside the one it plays */
    public const KEEP = 3;

    protected $fillable = [
        'music_video_id',
        'shot_id',
        'source',
        'file',
        'length',
        'synced_from',
        'synced_to',
        'prompt',
    ];

    protected function casts(): array
    {
        return [
            'length' => 'float',
            'synced_from' => 'float',
            'synced_to' => 'float',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(MusicVideo::class, 'music_video_id');
    }

    public function shot(): BelongsTo
    {
        return $this->belongsTo(MusicVideoShot::class, 'shot_id');
    }

    /**
     * Whether the take is lip-synced to a stretch of the song.
     */
    public function synced(): bool
    {
        return $this->synced_from !== null && $this->synced_to !== null;
    }
}
