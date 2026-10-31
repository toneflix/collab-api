<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One shot of a music video: a stretch of the song, a still and the clip
 * made from it.
 */
class MusicVideoShot extends Model
{
    /** The artist sings this stretch to the camera */
    public const PERFORMANCE = 'performance';

    public const BROLL = 'broll';

    protected $fillable = [
        'music_video_id',
        'position',
        'start',
        'end',
        'kind',
        'prompt',
        'keyframe',
        'clip',
        'characters',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'start' => 'float',
            'end' => 'float',
            'characters' => 'array',
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
