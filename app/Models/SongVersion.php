<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved copy of a song's title and lyrics.
 */
class SongVersion extends Model
{
    public const AUTOSAVE = 'autosave';

    public const NAMED = 'named';

    public const BEFORE_RESTORE = 'before_restore';

    public const BEFORE_AI = 'before_ai';

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'title',
        'body',
        'kind',
        'name',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
