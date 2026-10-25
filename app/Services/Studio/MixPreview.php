<?php

namespace App\Services\Studio;

use App\Broadcasting\Channels;
use App\Jobs\RenderMix;
use App\Models\ProjectTrack;
use App\Services\Realtime\Realtime;
use App\Services\Studio\Mix\MixRenderer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Toneflix\LaravelFileable\Media;

/**
 * A project track's preview mix, rendered on the server from the saved
 * arrangement. Each request gets an id, so only the newest one is kept.
 */
class MixPreview
{
    public const RENDERING = 'rendering';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public function __construct(protected MixRenderer $renderer) {}

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
     * Render the mix and keep it as the track's preview, unless a newer
     * request came in meanwhile.
     */
    public function render(ProjectTrack $track, string $id): void
    {
        if (! $this->current($track, $id)) {
            return;
        }

        $path = $this->renderer->render($track);

        if (! $path) {
            $this->status($track, ['status' => self::FAILED, 'id' => $id, 'error' => 'There is nothing to mix.']);

            return;
        }

        try {
            if (! $this->current($track->refresh(), $id)) {
                return;
            }

            $track->preview = (new Media)->save('music_tracks', new UploadedFile($path, 'preview.mp3', 'audio/mpeg', null, true), $track->preview);
            $track->meta = [...collect($track->meta)->all(), 'mix' => ['status' => self::READY, 'id' => $id, 'at' => now()->toIso8601String()]];
            $track->save();
        } finally {
            @unlink($path);
        }

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
