<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One shot of a music video: a stretch of the song, a still and the take
 * it plays.
 */
class MusicVideoShot extends Model
{
    /** The artist sings this stretch to the camera */
    public const PERFORMANCE = 'performance';

    public const BROLL = 'broll';

    /** Ways a shot can move over its still instead of playing a take */
    public const MOTIONS = ['zoom_in', 'zoom_out', 'pan'];

    /** The shortest a creator can make a shot */
    public const MIN_SECONDS = 1.0;

    /** Seconds a b-roll take may fall short of its shot, held on its last frame */
    public const HOLD_SECONDS = 1.0;

    protected $fillable = [
        'music_video_id',
        'position',
        'start',
        'end',
        'kind',
        'prompt',
        'keyframe',
        'take_id',
        'offset',
        'motion',
        'characters',
        'drawing',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'start' => 'float',
            'end' => 'float',
            'offset' => 'float',
            'characters' => 'array',
            'drawing' => 'boolean',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(MusicVideo::class, 'music_video_id');
    }

    /**
     * The take the shot plays.
     */
    public function take(): BelongsTo
    {
        return $this->belongsTo(MusicVideoTake::class, 'take_id');
    }

    /**
     * Every take made for the shot, newest first.
     */
    public function takes(): HasMany
    {
        return $this
            ->hasMany(MusicVideoTake::class, 'shot_id')
            ->latest('id');
    }

    public function length(): float
    {
        return round($this->end - $this->start, 2);
    }

    /**
     * Whether the shot plays its still with a zoom or pan instead of a take.
     */
    public function stillOnly(): bool
    {
        return $this->motion !== null;
    }

    /**
     * Seconds into the take the shot starts. A lip-synced take is cut where
     * the shot's lines fall in it, so the lips stay on the words.
     */
    public function takeOffset(): float
    {
        $take = $this->take;

        if ($take?->synced()) {
            return round(max(0, $this->start - $take->synced_from), 2);
        }

        return $this->offset;
    }

    /**
     * Whether the shot needs a new clip before the video can be stitched:
     * it has no take, a singing shot sits outside the lines its take was
     * synced to, or a b-roll take is too short for the shot.
     */
    public function needsClip(): bool
    {
        if ($this->stillOnly()) {
            return false;
        }

        $take = $this->take;

        if (! $take) {
            return true;
        }

        if ($this->kind === self::PERFORMANCE) {
            return ! $take->synced()
                || $this->start < $take->synced_from - 0.01
                || $this->end > $take->synced_to + 0.01;
        }

        return $take->length - $this->offset + self::HOLD_SECONDS < $this->length();
    }
}
