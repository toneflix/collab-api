<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved state of a project track's arrangement: its tempo, meter and the
 * stems that were on it, with who made the change and what changed.
 */
class ProjectTrackVersion extends Model
{
    public const ORIGINAL = 'original';

    public const SAVED = 'saved';

    public const ADDED = 'added';

    public const EDITED = 'edited';

    public const REMOVED = 'removed';

    public const RESTORED = 'restored';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'action',
        'summary',
        'changes',
        'snapshot',
        'stem_ids',
        'user_id',
        'restored_from_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'snapshot' => 'array',
            'stem_ids' => 'array',
        ];
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(ProjectTrack::class, 'track_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function restoredFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'restored_from_id');
    }
}
