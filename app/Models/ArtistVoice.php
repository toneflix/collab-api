<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How an artist sings: a few words for music prompts, a short a cappella
 * sample, and the voice a music engine built from it.
 *
 * @property string $status
 * @property ?string $file
 * @property ?float $seconds
 * @property ?string $persona_id
 * @property ?string $check_status
 */
class ArtistVoice extends Model
{
    /** No sample yet */
    public const EMPTY = 'empty';

    /** The AI is making the sample */
    public const GENERATING = 'generating';

    /** The sample can be sung with */
    public const READY = 'ready';

    /** The sample couldn't be made */
    public const FAILED = 'failed';

    /** An AI sample made for the artist */
    public const GENERATED = 'generated';

    /** A sample the artist's account uploaded or recorded */
    public const UPLOADED = 'upload';

    /** A voice built from a song the engine made */
    public const STYLE_PERSONA = 'style';

    /** A voice built from a person's own recording */
    public const VOICE_PERSONA = 'voice';

    /** The engine is writing a phrase to read */
    public const CHECK_PREPARING = 'preparing';

    /** Waiting for the owner to record the phrase */
    public const CHECK_RECORDING = 'recording';

    /** The engine is checking the recording and building the voice */
    public const CHECK_BUILDING = 'building';

    public const CHECK_DONE = 'done';

    public const CHECK_FAILED = 'failed';

    protected $fillable = [
        'description',
    ];

    protected $attributes = [
        'status' => self::EMPTY,
    ];

    protected function casts(): array
    {
        return [
            'seconds' => 'float',
        ];
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(AiJob::class, 'ai_job_id');
    }

    /**
     * Voices with a sample people can sing with.
     */
    public function scopeReady(Builder $query): void
    {
        $query
            ->where('status', self::READY)
            ->whereNotNull('file');
    }

    /**
     * The singer's gender from the description, `m` or `f`, or null when it
     * doesn't say.
     */
    public function gender(): ?string
    {
        $text = mb_strtolower((string) $this->description);

        return match (true) {
            (bool) preg_match('/\b(female|woman|girl|alto|soprano|mezzo)\b/', $text) => 'f',
            (bool) preg_match('/\b(male|man|boy|baritone|tenor|bass)\b/', $text) => 'm',
            default => null,
        };
    }

    public function hasSample(): bool
    {
        return $this->status === self::READY && (bool) $this->file;
    }

    /**
     * Whether the engine is still working on the sample or the voice.
     */
    public function isBusy(): bool
    {
        return $this->status === self::GENERATING
            || in_array($this->check_status, [self::CHECK_PREPARING, self::CHECK_BUILDING], true);
    }
}
