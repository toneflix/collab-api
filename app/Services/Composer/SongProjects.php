<?php

namespace App\Services\Composer;

use App\Enums\Feature;
use App\Models\AiJob;
use App\Models\Lyric;
use App\Models\ProjectTrack;
use App\Models\Song;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiStudio;
use App\Services\Ai\Credits;
use App\Services\Limits;
use App\Services\Lyrics\Lyrics;
use App\Services\Reputation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a Composer song into a project with one track carrying its lyric
 * sheet, and can have AI sing the lyrics onto that track.
 */
class SongProjects
{
    /** Song length asked for when the model takes one and the writer gave none */
    public const DEFAULT_SECONDS = 180;

    public function __construct(
        protected Lyrics $lyrics,
        protected AiStudio $studio,
    ) {}

    /**
     * Create the project and track. With `generate`, AI makes a song from the
     * lyrics on the track; that is checked before anything is created.
     *
     * @param  array{title?: ?string, privacy?: ?string, genre_id: string, artist_id: string, generate?: bool, prompt?: ?string, seconds?: ?int}  $data
     * @return array{track: ProjectTrack, job: ?AiJob}
     */
    public function create(Song $song, User $user, array $data): array
    {
        if ($song->track) {
            throw ValidationException::withMessages(['song' => 'This song already has a project. Update its lyric sheet instead.']);
        }

        if ($song->lyricLines() === []) {
            throw ValidationException::withMessages(['song' => 'Write some lyrics before making a project from this song.']);
        }

        Limits::for($user)->ensureBelow(
            Feature::MAX_PROJECTS,
            $user->projects()->count(),
            'title',
            'Your account can have up to :limit projects.'
        );

        $input = ! empty($data['generate']) ? $this->musicInput($song, $user, $data) : null;
        $title = trim((string) ($data['title'] ?? '')) ?: $song->title;

        $track = DB::transaction(function () use ($song, $user, $data, $title) {
            $project = $user
                ->projects()
                ->create([
                    'title' => $title,
                    'privacy' => $data['privacy'] ?? 'private',
                ]);

            $track = $project
                ->tracks()
                ->create([
                    'title' => $title,
                    'privacy' => 'private',
                    'genre_id' => $data['genre_id'],
                    'artist_id' => $data['artist_id'],
                ]);

            $this->lyrics->save($track, $song->lyricLines(), Lyric::WRITTEN, $user, ['language' => $song->editorSettings()['language']]);

            $song
                ->forceFill(['project_track_id' => $track->id])
                ->saveQuietly();

            return $track;
        });

        (new Reputation($user, $user->projects()->count() > 1 ? 'create_project' : 'first_project'))->add();

        $job = $input ? $this->studio->start($user, $track, 'music', $input) : null;

        return ['track' => $track, 'job' => $job];
    }

    /**
     * Put the song's current lyrics on its track's lyric sheet.
     */
    public function syncLyrics(Song $song, User $user): ?Lyric
    {
        $track = $song->track;

        if (! $track) {
            throw ValidationException::withMessages(['song' => 'This song has no project yet.']);
        }

        return $this->lyrics->save($track, $song->lyricLines(), Lyric::WRITTEN, $user, ['language' => $song->editorSettings()['language']]);
    }

    /**
     * The AI music job's input for the song, checked against the Composer
     * song model's limits and the writer's access and credits.
     *
     * @param  array{prompt?: ?string, seconds?: ?int}  $data
     * @return array<string, mixed>
     */
    protected function musicInput(Song $song, User $user, array $data): array
    {
        $model = AiSettings::composerMusicModel();
        $credits = Credits::for($user);
        $credits->ensureAccess('generate');

        $name = $model->name();

        if (! $model->engine()->available()) {
            throw ValidationException::withMessages(['generate' => "{$name} isn't set up yet, so AI can't sing this song."]);
        }

        $lyrics = trim((string) $song->body);
        [$min, $max] = $model->lyricsLength() ?? [1, 0];
        [$promptMin, $promptMax] = $model->promptLength();
        $prompt = trim((string) ($data['prompt'] ?? ''));

        if (mb_strlen($lyrics) < $min || mb_strlen($lyrics) > $max) {
            throw ValidationException::withMessages(['generate' => "{$name} takes lyrics of {$min} to {$max} characters. This song has " . mb_strlen($lyrics) . '.']);
        }

        if (mb_strlen($prompt) < $promptMin || mb_strlen($prompt) > $promptMax) {
            throw ValidationException::withMessages(['prompt' => "Describe the sound in {$promptMin} to {$promptMax} characters."]);
        }

        $seconds = $model->maxSeconds()
            ? min(max((int) ($data['seconds'] ?? self::DEFAULT_SECONDS), $model->minSeconds()), $model->maxSeconds(), AiSettings::musicMaxSeconds())
            : null;
        $cost = $model->credits((int) $seconds);

        if ($credits->total() < $cost) {
            throw ValidationException::withMessages(['generate' => "This needs {$cost} credits, but you have {$credits->total()}."]);
        }

        return array_filter([
            'model' => $model::ID,
            'prompt' => $prompt,
            'lyrics' => $lyrics,
            'seconds' => $seconds,
            'instrumental' => $model->instrumentalOption() ? false : null,
            'split' => false,
            ...$model->jobSettings(),
        ], fn($value) => $value !== null);
    }
}
