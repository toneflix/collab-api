<?php

namespace App\Models;

use App\Services\Agent\Actions\AgentAction;
use App\Services\Agent\Actions\CreateArtist;
use App\Services\Agent\Actions\CreateProject;
use App\Services\Agent\Actions\CreateTrack;
use App\Services\Agent\Actions\DrawArtwork;
use App\Services\Agent\Actions\MakeMusic;
use App\Services\Agent\Actions\MakeVoice;
use App\Services\Agent\Actions\MixTrack;
use App\Services\Agent\Actions\ReleaseAlbum;
use App\Services\Agent\Actions\SyncLyrics;
use App\Services\Agent\Actions\WriteDescriptions;
use App\Services\Agent\Actions\WriteSong;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a content agent run does, like writing a song or releasing an
 * album. A step starts once the steps it depends on are done.
 */
class AgentStep extends Model
{
    public const PENDING = 'pending';

    /** Started and waiting on queued work, like an AI job or a mix */
    public const WAITING = 'waiting';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    /**
     * @var array<string, class-string<AgentAction>>
     */
    public const ACTIONS = [
        'artist' => CreateArtist::class,
        'voice' => MakeVoice::class,
        'project' => CreateProject::class,
        'song' => WriteSong::class,
        'track' => CreateTrack::class,
        'music' => MakeMusic::class,
        'mix' => MixTrack::class,
        'artwork' => DrawArtwork::class,
        'lyrics' => SyncLyrics::class,
        'describe' => WriteDescriptions::class,
        'release' => ReleaseAlbum::class,
    ];

    protected $attributes = [
        'status' => self::PENDING,
        'position' => 0,
    ];

    protected $fillable = [
        'key',
        'kind',
        'position',
        'depends_on',
        'input',
        'output',
    ];

    protected function casts(): array
    {
        return [
            'depends_on' => 'array',
            'input' => 'array',
            'output' => 'array',
            'position' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }

    public function action(): AgentAction
    {
        return app(self::ACTIONS[$this->kind]);
    }

    /**
     * Steps that still have work to do.
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [self::PENDING, self::WAITING]);
    }

    /**
     * A value another step left in its output, by that step's key.
     */
    public function from(string $key, string $field): mixed
    {
        return $this->run
            ->steps()
            ->where('key', $key)
            ->toBase()
            ->value("output->{$field}");
    }

    /**
     * Wait for a free AI job slot. The step's time limit starts once its
     * job does, not while it waits for one.
     */
    public function waitForSlot(): bool
    {
        $this->started_at = null;

        return false;
    }

    /**
     * Merge values into the step's output and save it.
     *
     * @param  array<string, mixed>  $values
     */
    public function remember(array $values): void
    {
        $this
            ->forceFill(['output' => [...($this->output ?? []), ...$values]])
            ->save();
    }
}
