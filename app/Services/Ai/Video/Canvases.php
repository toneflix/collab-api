<?php

namespace App\Services\Ai\Video;

use App\Enums\HttpStatus;
use App\Models\Track;
use App\Models\TrackCanvas;
use App\Models\User;
use App\Services\Ai\AiStudio;
use App\Services\Video\VideoFiles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Track canvases: the looping clip a released track shows in the player. A
 * track keeps one ready canvas; a new one replaces it once it's ready, and a
 * canvas still being made blocks another.
 */
class Canvases
{
    public function __construct(
        protected AiStudio $studio,
        protected CanvasStorage $storage,
        protected VideoFiles $files,
    ) {}

    /**
     * The canvas listeners see: the owner's newest ready one.
     */
    public function forTrack(Track $track): ?TrackCanvas
    {
        $owner = $track->release?->user_id;

        if (! $owner) {
            return null;
        }

        return TrackCanvas::query()
            ->where('track_id', $track->id)
            ->where('user_id', $owner)
            ->ready()
            ->latest('id')
            ->first();
    }

    /**
     * The canvas the owner works on: one being made or that failed when it's
     * newer than the ready one, else the ready one.
     */
    public function latest(Track $track): ?TrackCanvas
    {
        return TrackCanvas::query()
            ->where('track_id', $track->id)
            ->latest('id')
            ->first();
    }

    /**
     * Start making a canvas with AI from the track's artwork.
     */
    public function generate(User $user, Track $track, ?string $brief = null): TrackCanvas
    {
        $this->ensureOwner($user, $track);
        $this->ensureIdle($track);

        if (! $track->hasArtwork()) {
            throw ValidationException::withMessages(['track' => 'Add artwork to the track first.']);
        }

        $this->dropUnready($track);

        $canvas = TrackCanvas::query()
            ->create([
                'user_id' => $user->id,
                'track_id' => $track->id,
                'source' => TrackCanvas::AI,
                'brief' => $brief,
            ]);

        try {
            $this->studio->start($user, null, 'canvas', [
                'canvas_id' => $canvas->id,
                'title' => $track->title,
            ]);
        } catch (Throwable $e) {
            $canvas->delete();

            throw $e;
        }

        return $canvas;
    }

    /**
     * Use the owner's own clip as the canvas.
     */
    public function upload(User $user, Track $track, UploadedFile $clip): TrackCanvas
    {
        $this->ensureOwner($user, $track);
        $this->ensureIdle($track);

        try {
            $length = $this->files->length($clip->getRealPath());
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['clip' => 'That clip couldn\'t be read.']);
        }

        if ($length < TrackCanvas::MIN_SECONDS) {
            throw ValidationException::withMessages(['clip' => 'The clip must be at least ' . TrackCanvas::MIN_SECONDS . ' seconds long.']);
        }

        $this->dropUnready($track);

        $canvas = TrackCanvas::query()
            ->create([
                'user_id' => $user->id,
                'track_id' => $track->id,
                'source' => TrackCanvas::UPLOAD,
            ]);
        $loop = null;

        try {
            $loop = $this->files->normalize($clip->getRealPath(), TrackCanvas::SIZE, TrackCanvas::MAX_SECONDS);

            return $this->finish($canvas, $loop);
        } catch (Throwable $e) {
            $this->delete($canvas);

            throw $e;
        } finally {
            if ($loop) {
                @unlink($loop);
            }
        }
    }

    /**
     * Keep a finished loop and its first frame, and make the canvas the
     * track's own, replacing the one before.
     */
    public function finish(TrackCanvas $canvas, string $loop): TrackCanvas
    {
        $poster = $this->files->poster($loop);

        try {
            $canvas
                ->forceFill([
                    'status' => TrackCanvas::READY,
                    'file' => $this->storage->put($canvas, $loop, 'canvas', 'mp4'),
                    'poster' => $this->storage->put($canvas, $poster, 'poster', 'jpg'),
                    'duration' => $this->files->length($loop),
                    'error' => null,
                ])
                ->save();
        } finally {
            @unlink($poster);
        }

        TrackCanvas::query()
            ->where('track_id', $canvas->track_id)
            ->whereKeyNot($canvas->id)
            ->lazyById()
            ->each(fn(TrackCanvas $old) => $this->delete($old));

        return $canvas;
    }

    /**
     * Take the canvas off the track.
     */
    public function remove(User $user, Track $track): void
    {
        $this->ensureOwner($user, $track);
        $this->ensureIdle($track);

        TrackCanvas::query()
            ->where('track_id', $track->id)
            ->lazyById()
            ->each(fn(TrackCanvas $canvas) => $this->delete($canvas));
    }

    /**
     * Remove the files of every canvas of these tracks, before the tracks are
     * deleted and the database drops the canvases with them.
     */
    public function forgetForTracks(Builder|Relation $tracks): void
    {
        $query = $tracks instanceof Relation ? $tracks->getQuery() : $tracks;
        $ids = (clone $query)
            ->withoutGlobalScopes()
            ->select('tracks.id');

        TrackCanvas::query()
            ->whereIn('track_id', $ids)
            ->select('id')
            ->chunkById(100, fn($canvases) => $canvases->each(fn(TrackCanvas $canvas) => $this->storage->forget($canvas)));
    }

    protected function delete(TrackCanvas $canvas): void
    {
        $this->storage->forget($canvas);
        $canvas->delete();
    }

    /**
     * Clear canvases that failed, so the track keeps only its ready one.
     */
    protected function dropUnready(Track $track): void
    {
        TrackCanvas::query()
            ->where('track_id', $track->id)
            ->where('status', TrackCanvas::FAILED)
            ->lazyById()
            ->each(fn(TrackCanvas $canvas) => $this->delete($canvas));
    }

    protected function ensureOwner(User $user, Track $track): void
    {
        abort_unless($track->release?->isOwnedBy($user), HttpStatus::FORBIDDEN->value, 'You can only change the canvas of your own tracks.');
    }

    protected function ensureIdle(Track $track): void
    {
        $drawing = TrackCanvas::query()
            ->where('track_id', $track->id)
            ->where('status', TrackCanvas::DRAWING)
            ->exists();

        if ($drawing) {
            throw ValidationException::withMessages(['canvas' => 'Wait for the canvas being made to finish.']);
        }
    }
}
