<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A report about some content. People file reports (type "report") and the
 * audio checks file matches (type "match", with no user).
 *
 * @property string $status
 * @property string $type
 * @property string $reason
 * @property array<string, mixed>|null $data
 */
class Safety extends Model
{
    use HasFactory;

    public const PENDING = 'pending';

    public const DISMISSED = 'dismissed';

    public const ACTIONED = 'actioned';

    public const TYPE_REPORT = 'report';

    public const TYPE_MATCH = 'match';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'model_type',
        'model_id',
        'type',
        'reason',
        'details',
        'data',
        'status',
        'reviewed_by',
        'reviewed_at',
        'takedown_id',
    ];

    protected $attributes = [
        'status' => self::PENDING,
    ];

    public static $allowedReasons = [
        "dmca",
        "spam",
        "hate",
        "abuse",
        "violent",
        "privacy",
        "sensitive",
        "self-harm",
        "copyright",
        "child-safety",
    ];

    /**
     * Reasons that are claims about someone else's work.
     */
    public static $copyrightReasons = ['copyright', 'dmca'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeForItem(Builder $query, Model $model)
    {
        $query
            ->whereModelType($model->getMorphClass())
            ->whereModelId($model->getKey());
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    /**
     * Get the user that made the repport
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function takedown(): BelongsTo
    {
        return $this->belongsTo(Takedown::class);
    }
}
