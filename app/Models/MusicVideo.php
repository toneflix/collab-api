<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An AI music video for a released track: a storyboard of shots the owner
 * checks first, then the rendered video with the song under it.
 */
class MusicVideo extends Model
{
    /** The scene plan and stills are being drawn */
    public const PLANNING = 'planning';

    /** Stills are ready for the owner to check before rendering */
    public const STORYBOARD = 'storyboard';

    public const RENDERING = 'rendering';

    public const READY = 'ready';

    /** Drawing the storyboard failed; a failed render goes back to the storyboard */
    public const FAILED = 'failed';

    public const RATIOS = ['16:9', '9:16', '1:1'];

    public const CREDITS = 'credits';

    public const MINUTES = 'minutes';

    protected $attributes = [
        'status' => self::PLANNING,
        'ratio' => '16:9',
    ];

    protected $fillable = [
        'user_id',
        'track_id',
        'status',
        'ratio',
        'brief',
        'portrait',
        'style',
        'duration',
        'billing',
        'file',
        'cut',
        'error',
        'billed_at',
        'finished_at',
        'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'style' => 'array',
            'duration' => 'float',
            'cut' => 'array',
            'billed_at' => 'datetime',
            'finished_at' => 'datetime',
            'edited_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function shots(): HasMany
    {
        return $this
            ->hasMany(MusicVideoShot::class)
            ->orderBy('position');
    }

    public function takes(): HasMany
    {
        return $this->hasMany(MusicVideoTake::class);
    }

    public function characters(): HasMany
    {
        return $this
            ->hasMany(MusicVideoCharacter::class)
            ->orderByDesc('singer')
            ->orderBy('id');
    }

    /**
     * Width and height of the rendered video.
     *
     * @return array{0: int, 1: int}
     */
    public function size(): array
    {
        return match ($this->ratio) {
            '9:16' => [720, 1280],
            '1:1' => [720, 720],
            default => [1280, 720],
        };
    }

    /**
     * The ratio as two numbers, like [16, 9].
     *
     * @return array{0: int, 1: int}
     */
    public function ratioParts(): array
    {
        [$width, $height] = array_map('intval', explode(':', $this->ratio));

        return [$width, $height];
    }

    /**
     * Started minutes of the song, which renders are priced by.
     */
    public function startedMinutes(): int
    {
        return max(1, (int) ceil($this->duration / 60));
    }

    /**
     * Whether the shots changed since the video was last stitched.
     */
    public function edited(): bool
    {
        return $this->edited_at !== null;
    }
}
