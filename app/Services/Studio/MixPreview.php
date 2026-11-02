<?php

namespace App\Services\Studio;

use App\Broadcasting\Channels;
use App\Jobs\RenderMix;
use App\Models\ProjectTrack;
use App\Services\Audio\StemAudio;
use App\Services\Realtime\Realtime;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Toneflix\LaravelFileable\Media;

/**
 * A project track's preview mix, made by the studio and uploaded: from the
 * browser of whoever saved it, or from headless Chrome on the worker when
 * nobody has the studio open. Each mix gets an id, so only the newest one
 * is kept.
 */
class MixPreview
{
    public const RENDERING = 'rendering';

    public const READY = 'ready';

    public const FAILED = 'failed';

    /** Highest peak a mix may reach, in dBFS */
    public const CEILING = -0.1;

    public function __construct(
        protected BrowserMix $browser,
        protected StemAudio $audio,
    ) {}

    /**
     * The studio's master volume for the track, from 0 to 1.
     */
    public static function masterVolume(ProjectTrack $track): float
    {
        $volume = $track->meta['mixVolume'] ?? 1;

        return is_numeric($volume) ? max(0, min(1, (float) $volume)) : 1.0;
    }

    /**
     * Ask for a new preview mix of the track as it's saved now.
     */
    public function queue(ProjectTrack $track): void
    {
        $id = (string) Str::uuid();

        $this->status($track, ['status' => self::RENDERING, 'id' => $id]);

        RenderMix::dispatch($track, $id)
            ->afterCommit();
    }

    /**
     * Have the studio mix the track in headless Chrome. The render page
     * uploads the mix, which is kept unless a newer request came in.
     */
    public function render(ProjectTrack $track, string $id): void
    {
        if (! $this->current($track, $id)) {
            return;
        }

        $result = $this->browser->render($track, $id);

        if ($result === BrowserMix::EMPTY && $this->current($track->refresh(), $id)) {
            $this->status($track, ['status' => self::FAILED, 'id' => $id, 'error' => 'There is nothing to mix.']);
        }
    }

    /**
     * Keep a mix the studio made as the track's preview. A mix for request
     * `$id` is kept only while that request is the newest; one without an
     * id, from someone's studio, replaces any render still running.
     */
    public function upload(ProjectTrack $track, string $path, ?string $id = null): ProjectTrack
    {
        if ($id !== null && ! $this->current($track, $id)) {
            return $track;
        }

        $peak = $this->audio->peak($path);
        $mp3 = $this->audio->mp3($path, $peak !== null && $peak > self::CEILING ? self::CEILING - $peak : 0.0);

        try {
            $this->keep($track, $mp3, $id ?? (string) Str::uuid());
        } finally {
            @unlink($mp3);
        }

        return $track;
    }

    /**
     * Save an MP3 as the track's preview and tell everyone it's ready.
     */
    protected function keep(ProjectTrack $track, string $mp3, string $id): void
    {
        $track->preview = (new Media)->save('music_tracks', new UploadedFile($mp3, 'preview.mp3', 'audio/mpeg', null, true), $track->preview);
        $track->meta = [...collect($track->meta)->all(), 'mix' => ['status' => self::READY, 'id' => $id, 'at' => now()->toIso8601String()]];
        $track->save();

        $this->announce($track);
    }

    public function fail(ProjectTrack $track, string $id, Throwable $error): void
    {
        Log::error('Mix render failed', ['track' => $track->id, 'exception' => $error]);

        if ($this->current($track->refresh(), $id)) {
            $this->status($track, ['status' => self::FAILED, 'id' => $id, 'error' => 'The preview couldn\'t be mixed. Save again to try once more.']);
        }
    }

    /**
     * Where the track's newest mix request is: rendering, ready or failed.
     *
     * @return array{status: string, error?: string}|null
     */
    public static function state(ProjectTrack $track): ?array
    {
        $mix = $track->meta['mix'] ?? null;

        return is_array($mix) && isset($mix['status'])
            ? array_filter(['status' => $mix['status'], 'error' => $mix['error'] ?? null])
            : null;
    }

    protected function current(ProjectTrack $track, string $id): bool
    {
        return ($track->meta['mix']['id'] ?? null) === $id;
    }

    /**
     * @param  array<string, string>  $mix
     */
    protected function status(ProjectTrack $track, array $mix): void
    {
        $track->meta = [...collect($track->meta)->all(), 'mix' => $mix];
        $track->saveQuietly();

        $this->announce($track);
    }

    /**
     * Tell everyone on the track. Preview links are per person, so clients
     * fetch the track again for theirs.
     */
    protected function announce(ProjectTrack $track): void
    {
        Realtime::send(Channels::projectTrack($track), 'track.mix', [
            'track_id' => $track->id,
            ...(self::state($track) ?? []),
        ]);
    }
}
