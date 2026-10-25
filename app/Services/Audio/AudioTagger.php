<?php

namespace App\Services\Audio;

use App\Broadcasting\Channels;
use App\Enums\MusicalKey;
use App\Jobs\TagAudio;
use App\Models\ProjectTrack;
use App\Models\Track;
use App\Services\Audio\Analysis\AudioAnalyzer;
use App\Services\Realtime\Realtime;
use Illuminate\Database\Eloquent\Builder;

/**
 * Saves the tempo and key tags of released tracks and project tracks.
 * Detected tags never replace ones a creator set, and a project track's tags
 * pass on to draft tracks released from it.
 */
class AudioTagger
{
    /** Guesses less sure than this are left out */
    public const MIN_CONFIDENCE = 0.2;

    public function __construct(
        protected AudioAnalyzer $analyzer,
        protected TrackAudio $audio,
    ) {}

    /**
     * Analyse the audio and save what was found. Returns false when there was
     * no audio to analyse or the creator's own tags are kept.
     */
    public function detect(Track|ProjectTrack $owner): bool
    {
        if ($owner->tags_source === Track::TAGS_MANUAL) {
            return false;
        }

        $local = $owner instanceof Track
            ? $this->audio->local($owner, withPreview: true)
            : $this->audio->localPreview($owner);

        if (! $local) {
            return false;
        }

        try {
            $tags = $this->analyzer->analyze($local, (float) $owner->duration);
        } finally {
            @unlink($local);
        }

        $this->save(
            $owner,
            $tags && $tags->bpmConfidence >= self::MIN_CONFIDENCE ? $tags->bpm : null,
            $tags && $tags->keyConfidence >= self::MIN_CONFIDENCE ? $tags->key : null,
            Track::TAGS_DETECTED,
        );

        return true;
    }

    /**
     * Save tags a creator chose. Clearing both hands the tags back to detection.
     */
    public function set(Track|ProjectTrack $owner, ?float $bpm, ?MusicalKey $key): void
    {
        if ($bpm === null && $key === null) {
            $this->save($owner, null, null, null);
            TagAudio::dispatch($owner)
                ->afterCommit();

            return;
        }

        $this->save($owner, $bpm === null ? null : round($bpm, 1), $key, Track::TAGS_MANUAL);
    }

    /**
     * Copy a project track's tags to a track released from it. Returns false
     * when the project track has none yet.
     */
    public function inherit(Track $track): bool
    {
        $project = $track
            ->projectTrack()
            ->withoutGlobalScopes()
            ->first();

        if (! $project?->tagged_at || $track->tags_source === Track::TAGS_MANUAL) {
            return false;
        }

        $this->write($track, $project->bpm, $project->musical_key, $project->tags_source);

        return true;
    }

    protected function save(Track|ProjectTrack $owner, ?float $bpm, ?MusicalKey $key, ?string $source): void
    {
        $this->write($owner, $bpm, $key, $source);

        if (! $owner instanceof ProjectTrack) {
            return;
        }

        $owner->releasedTracks()
            ->whereHas('release', fn(Builder $q) => $q->where('draft', true))
            ->where(fn(Builder $q) => $q->whereNull('tags_source')->orWhere('tags_source', '!=', Track::TAGS_MANUAL))
            ->chunkById(100, fn($tracks) => $tracks->each(fn(Track $track) => $this->write($track, $bpm, $key, $source)));

        Realtime::send(Channels::projectTrack($owner), 'tags.updated', [
            'track_id' => $owner->id,
            'tags' => $owner->audioTags(),
        ]);
    }

    protected function write(Track|ProjectTrack $owner, ?float $bpm, ?MusicalKey $key, ?string $source): void
    {
        $owner->bpm = $bpm;
        $owner->musical_key = $key;
        $owner->tags_source = $source;
        $owner->tagged_at = $source ? now() : null;
        $owner->saveQuietly();
    }
}
