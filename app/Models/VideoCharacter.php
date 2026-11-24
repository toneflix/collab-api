<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A character a creator saved with its sheet, to cast in any of their music videos.
 */
class VideoCharacter extends Model
{
    /** Characters one person may keep */
    public const MAX = 40;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'sheet',
        'uploaded',
    ];

    protected function casts(): array
    {
        return [
            'uploaded' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function castings(): HasMany
    {
        return $this->hasMany(MusicVideoCharacter::class);
    }
}
