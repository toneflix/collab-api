<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The lyrics of a released track or a project track, as timed lines.
 *
 * @property list<array{start: ?float, end: ?float, text: string}> $lines
 */
class Lyric extends Model
{
    public const TRANSCRIBED = 'transcribed';

    public const WRITTEN = 'written';

    protected $fillable = [
        'lines',
        'text',
        'language',
        'source',
        'explicit',
        'flagged',
        'ai_job_id',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'flagged' => 'array',
            'explicit' => 'boolean',
        ];
    }

    public function lyricable(): MorphTo
    {
        return $this->morphTo();
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Whether the lines carry times the player can follow.
     */
    public function synced(): bool
    {
        return collect($this->lines)->contains(fn (array $line) => $line['start'] !== null);
    }
}
