<?php

namespace App\Models;

use App\Services\Ai\Tasks\AiTask;
use App\Services\Ai\Tasks\GenerateMusic;
use App\Services\Ai\Tasks\SplitStems;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One run of an AI tool for a user, such as generating music for a track.
 */
class AiJob extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    /**
     * @var array<string, class-string<AiTask>>
     */
    public const TASKS = [
        'music' => GenerateMusic::class,
        'stems' => SplitStems::class,
    ];

    protected $attributes = [
        'status' => self::QUEUED,
        'credits' => 0,
    ];

    protected $fillable = [
        'user_id',
        'project_track_id',
        'kind',
        'status',
        'input',
        'output',
        'credits',
        'error',
        'failure',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'credits' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(ProjectTrack::class, 'project_track_id');
    }

    public function creditEntries(): MorphMany
    {
        return $this->morphMany(AiCreditEntry::class, 'source');
    }

    public function task(): AiTask
    {
        return app(self::TASKS[$this->kind]);
    }

    public function scopeUnfinished(Builder $query): void
    {
        $query->whereIn('status', [self::QUEUED, self::RUNNING]);
    }

    /**
     * The job as the studio shows it.
     *
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'status' => $this->status,
            'trackId' => $this->project_track_id,
            'prompt' => $this->input['prompt'] ?? null,
            'seconds' => $this->input['seconds'] ?? null,
            'split' => (bool) ($this->input['split'] ?? $this->kind === 'stems'),
            'credits' => $this->credits,
            'stems' => $this->output['stems'] ?? [],
            'error' => $this->error,
            'createdAt' => $this->created_at,
            'finishedAt' => $this->finished_at,
        ];
    }
}
