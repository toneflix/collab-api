<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone who appears in a music video, with the character sheet every still
 * of them is drawn from so they look the same in each shot.
 */
class MusicVideoCharacter extends Model
{
    /** Characters one video may have */
    public const MAX = 4;

    /** The key of the artist who sings */
    public const SINGER = 'singer';

    protected $fillable = [
        'music_video_id',
        'key',
        'name',
        'description',
        'singer',
        'sheet',
        'provided',
        'video_character_id',
    ];

    protected function casts(): array
    {
        return [
            'singer' => 'boolean',
            'provided' => 'boolean',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(MusicVideo::class, 'music_video_id');
    }

    /**
     * The saved character this one was cast from or saved as.
     */
    public function savedAs(): BelongsTo
    {
        return $this->belongsTo(VideoCharacter::class, 'video_character_id');
    }
}
