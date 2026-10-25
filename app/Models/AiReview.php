<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One look the AI moderator takes at a claim or release waiting for review.
 *
 * Manual reviews, sent by an admin, always end in a decision. Automatic ones
 * are flagged for a person when the moderator isn't confident enough.
 *
 * @property string $mode
 * @property string $status
 * @property string|null $decision
 */
class AiReview extends Model
{
    public const MANUAL = 'manual';

    public const AUTO = 'auto';

    public const QUEUED = 'queued';

    public const DECIDED = 'decided';

    /** The moderator wasn't confident enough, so a person decides */
    public const FLAGGED = 'flagged';

    public const FAILED = 'failed';

    /** The item was decided some other way before the moderator got to it */
    public const SKIPPED = 'skipped';

    public const APPROVE = 'approve';

    public const REJECT = 'reject';

    public const UNSURE = 'unsure';

    protected $fillable = [
        'mode',
        'status',
        'decision',
        'confidence',
        'summary',
        'note',
        'error',
        'model',
        'requested_by',
        'decided_at',
    ];

    protected $attributes = [
        'status' => self::QUEUED,
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo()->withoutGlobalScopes();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by')->withoutGlobalScopes();
    }

    public function scopeQueued(Builder $query): void
    {
        $query->where('status', self::QUEUED);
    }

    public function isManual(): bool
    {
        return $this->mode === self::MANUAL;
    }
}
