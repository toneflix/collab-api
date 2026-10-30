<?php

namespace App\Services\Audio;

use App\Broadcasting\Channels;
use App\Enums\Mood;
use App\Jobs\TagStyle;
use App\Models\Genre;
use App\Models\Lyric;
use App\Models\ProjectTrack;
use App\Models\Track;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Styles\StyleModel;
use App\Services\Composer\Ai\LyricModel;
use App\Services\Realtime\Realtime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Saves the moods and suggested genre of released tracks and project tracks.
 * Moods come from the audio, with the lyrics as a second opinion. Detected
 * moods never replace ones a creator set, the creator's genre is never
 * changed, and a project track's tags pass on to draft tracks released
 * from it.
 */
class StyleTagger
{
    /**
     * The top genre is only suggested when it scores at least this. Zero-shot
     * genre is right about half the time at this level, so it stays a hint.
     */
    public const GENRE_MIN = 0.5;

    /** Moods scoring less than this are left out */
    public const MOOD_MIN = 0.15;

    public const MAX_MOODS = 3;

    /** How much the lyrics count towards a mood, against the audio */
    public const LYRICS_WEIGHT = 0.3;

    /** Characters of lyrics the language model reads */
    public const LYRICS_CHARS = 3000;

    public function __construct(
        protected StyleModel $model,
        protected TrackAudio $audio,
        protected LyricModel $writer,
    ) {}

    /**
     * Listen to the audio and save what was heard. Returns false when there
     * was no audio to listen to.
     */
    public function detect(Track|ProjectTrack $owner): bool
    {
        $local = $owner instanceof Track
            ? $this->audio->local($owner, withPreview: true)
            : $this->audio->localPreview($owner);

        if (! $local) {
            return false;
        }

        $genres = Genre::query()
            ->orderBy('id')
            ->get(['id', 'title', 'prompt']);

        $labels = [
            'genre' => $genres
                ->mapWithKeys(fn(Genre $genre) => [$genre->id => $genre->stylePrompt()])
                ->all(),
            'mood' => collect(Mood::cases())
                ->mapWithKeys(fn(Mood $mood) => [$mood->value => $mood->prompt()])
                ->all(),
        ];

        try {
            $scores = $this->model->score($local, (float) $owner->duration, $labels);
        } finally {
            @unlink($local);
        }

        $genreScores = collect($scores['genre'] ?? [])
            ->sortDesc()
            ->take(5)
            ->map(fn(float $score) => round($score, 4));

        $this->save($owner, [
            'genre' => $genreScores->all(),
            'mood' => collect($scores['mood'] ?? [])
                ->map(fn(float $score) => round($score, 4))
                ->all(),
            'lyrics' => $this->lyricMoods($owner),
        ]);

        return true;
    }

    /**
     * Read the lyrics again and update the moods, keeping the audio scores.
     * Returns false when the audio hasn't been heard yet.
     */
    public function relisten(Track|ProjectTrack $owner): bool
    {
        if (! $owner->style_scores) {
            return false;
        }

        $this->save($owner, [
            ...$owner->style_scores,
            'lyrics' => $this->lyricMoods($owner),
        ]);

        return true;
    }

    /**
     * Save moods a creator chose. Sending none hands the moods back to
     * detection.
     *
     * @param  list<Mood>  $moods
     */
    public function set(Track|ProjectTrack $owner, array $moods): void
    {
        if (! $moods) {
            $owner->moods_source = null;

            $this->save($owner, $owner->style_scores);

            if (! $owner->style_scores) {
                TagStyle::dispatch($owner)
                    ->afterCommit();
            }

            return;
        }

        $owner->moods = collect($moods)
            ->map(fn(Mood $mood) => $mood->value)
            ->unique()
            ->take(self::MAX_MOODS)
            ->values()
            ->all();
        $owner->moods_source = Track::TAGS_MANUAL;

        $this->save($owner, $owner->style_scores);
    }

    /**
     * Copy a project track's moods and suggestion to a track released from it.
     * Returns false when the project track has none yet.
     */
    public function inherit(Track $track): bool
    {
        $project = $track
            ->projectTrack()
            ->withoutGlobalScopes()
            ->first();

        if (! $project?->styled_at) {
            return false;
        }

        $this->copy($project, $track);

        return true;
    }

    /**
     * The moods a scored song gets: audio scores blended with the moods its
     * lyrics carry, strongest first.
     *
     * @param  array<string, mixed>  $scores
     * @return list<string>
     */
    public function moods(array $scores): array
    {
        $lyrics = (array) ($scores['lyrics'] ?? []);
        $share = $lyrics ? 1 / count($lyrics) : 0;
        $weight = $lyrics ? self::LYRICS_WEIGHT : 0;

        return collect(Mood::cases())
            ->mapWithKeys(fn(Mood $mood) => [
                $mood->value => (1 - $weight) * (float) ($scores['mood'][$mood->value] ?? 0)
                    + $weight * (in_array($mood->value, $lyrics, true) ? $share : 0),
            ])
            ->filter(fn(float $score) => $score >= self::MOOD_MIN)
            ->sortDesc()
            ->keys()
            ->take(self::MAX_MOODS)
            ->values()
            ->all();
    }

    /**
     * The genre a scored song sounds like, when the model is sure enough.
     *
     * @param  array<string, mixed>  $scores
     */
    public function genre(array $scores): ?int
    {
        $top = collect((array) ($scores['genre'] ?? []))
            ->sortDesc()
            ->take(1);

        return $top->isNotEmpty() && $top->first() >= self::GENRE_MIN
            ? (int) $top->keys()->first()
            : null;
    }

    /**
     * The moods the lyrics carry, as a language model reads them. Empty for
     * instrumentals, or when reading lyrics is off.
     *
     * @return list<string>
     */
    protected function lyricMoods(Track|ProjectTrack $owner): array
    {
        if (! AiSettings::stylesFromLyrics() || ! $this->writer->available()) {
            return [];
        }

        $text = Lyric::query()
            ->whereMorphedTo('lyricable', $owner)
            ->value('text');

        if (blank($text)) {
            return [];
        }

        $names = implode(', ', array_column(Mood::cases(), 'value'));
        $system = 'You tag songs with moods. Read the lyrics and answer with the one to three moods they carry, '
            . "comma separated, from this list only: {$names}. Answer with the moods and nothing else.";

        $answer = rescue(fn() => $this->writer->ask($system, Str::limit((string) $text, self::LYRICS_CHARS), 30), '', report: false);

        return collect(Mood::parse(str_replace(["\n", '.'], ',', (string) $answer)))
            ->take(self::MAX_MOODS)
            ->map(fn(Mood $mood) => $mood->value)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $scores
     */
    protected function save(Track|ProjectTrack $owner, ?array $scores): void
    {
        $this->write($owner, $scores);

        if (! $owner instanceof ProjectTrack) {
            return;
        }

        $owner->releasedTracks()
            ->whereHas('release', fn(Builder $q) => $q->where('draft', true))
            ->chunkById(100, fn($tracks) => $tracks->each(fn(Track $track) => $this->copy($owner, $track)));

        Realtime::send(Channels::projectTrack($owner), 'styles.updated', [
            'track_id' => $owner->id,
            'styles' => $owner->styleTags(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $scores
     */
    protected function write(Track|ProjectTrack $owner, ?array $scores): void
    {
        if ($owner->moods_source !== Track::TAGS_MANUAL) {
            $owner->moods = $scores ? $this->moods($scores) : null;
            $owner->moods_source = $scores ? Track::TAGS_DETECTED : null;
        }

        $owner->suggested_genre_id = $scores ? $this->genre($scores) : null;
        $owner->style_scores = $scores;
        $owner->styled_at = $scores ? now() : null;
        $owner->saveQuietly();
    }

    /**
     * Give a released track its project track's tags, keeping moods the
     * track's creator set on it.
     */
    protected function copy(ProjectTrack $from, Track $to): void
    {
        if ($to->moods_source !== Track::TAGS_MANUAL) {
            $to->moods = $from->moods;
            $to->moods_source = $from->moods_source;
        }

        $to->suggested_genre_id = $from->suggested_genre_id;
        $to->style_scores = $from->style_scores;
        $to->styled_at = $from->styled_at;
        $to->saveQuietly();
    }
}
