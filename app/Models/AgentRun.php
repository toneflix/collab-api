<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One prompt given to the content agent: the plan it made from it and the
 * artists, songs, tracks and albums it builds for the owner account.
 */
class AgentRun extends Model
{
    public const QUEUED = 'queued';

    public const PLANNING = 'planning';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /**
     * The lists runs are shown in, by the statuses in each.
     *
     * @var array<string, list<string>>
     */
    public const VIEWS = [
        'open' => [self::QUEUED, self::PLANNING, self::RUNNING],
        'succeeded' => [self::SUCCEEDED],
        'failed' => [self::FAILED, self::CANCELLED],
    ];

    protected $attributes = [
        'status' => self::QUEUED,
        'credits' => 0,
    ];

    protected $fillable = [
        'user_id',
        'owner_id',
        'prompt',
        'options',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'plan' => 'array',
            'credits' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AgentStep::class);
    }

    public function creditEntries(): MorphMany
    {
        return $this->morphMany(AiCreditEntry::class, 'source');
    }

    /**
     * Runs being planned or built.
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [self::PLANNING, self::RUNNING]);
    }

    /**
     * Runs in one of the VIEWS lists, or all of them without one.
     */
    public function scopeInView(Builder $query, ?string $view): void
    {
        $query->when($view, fn(Builder $q) => $q->whereIn('status', self::VIEWS[$view]));
    }

    /**
     * What a list of runs shows: who they are for and how far along they are.
     */
    public function scopeForList(Builder $query): void
    {
        $query
            ->with(['owner', 'user'])
            ->withCount([
                'steps',
                'steps as done_steps_count' => fn(Builder $q) => $q->where('status', AgentStep::DONE),
            ]);
    }

    /**
     * Load what a run's page shows. Its steps are capped by the planner's limits.
     */
    public function loadForPage(): self
    {
        return $this->load(['owner', 'user', 'steps' => fn($q) => $q->orderBy('position')]);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::PLANNING, self::RUNNING], true);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::SUCCEEDED, self::FAILED, self::CANCELLED], true);
    }

    /**
     * A run option with its default.
     */
    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }
}
