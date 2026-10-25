<?php

namespace App\Models;

use App\Traits\HasAiReviews;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone's request to manage an artist that no account manages yet, with
 * their proof of management rights.
 *
 * @property string $status
 * @property array<int, string> $links
 */
class ArtistClaim extends Model
{
    use HasAiReviews;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /**
     * How the claimant is connected to the artist.
     */
    public const ROLES = ['artist', 'manager', 'label', 'other'];

    protected $fillable = [
        'user_id',
        'role',
        'name',
        'email',
        'links',
        'message',
        'document',
        'status',
    ];

    protected $attributes = [
        'status' => self::PENDING,
    ];

    protected function casts(): array
    {
        return [
            'links' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function user(): BelongsTo
    {
        return $this
            ->belongsTo(User::class)
            ->withoutGlobalScopes();
    }

    public function reviewer(): BelongsTo
    {
        return $this
            ->belongsTo(User::class, 'reviewed_by')
            ->withoutGlobalScopes();
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', self::PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
