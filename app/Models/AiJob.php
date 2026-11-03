<?php

namespace App\Models;

use App\Services\Ai\Tasks\AddVocals;
use App\Services\Ai\Tasks\AiTask;
use App\Services\Ai\Tasks\DrawStoryboard;
use App\Services\Ai\Tasks\EditSection;
use App\Services\Ai\Tasks\GenerateArtwork;
use App\Services\Ai\Tasks\GenerateMusic;
use App\Services\Ai\Tasks\GenerateVoice;
use App\Services\Ai\Tasks\RenderVideo;
use App\Services\Ai\Tasks\SplitStems;
use App\Services\Ai\Tasks\TranscribeLyrics;
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
        'lyrics' => TranscribeLyrics::class,
        'edit' => EditSection::class,
        'vocals' => AddVocals::class,
        'artwork' => GenerateArtwork::class,
        'voice' => GenerateVoice::class,
        'video_storyboard' => DrawStoryboard::class,
        'video_render' => RenderVideo::class,
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
}
