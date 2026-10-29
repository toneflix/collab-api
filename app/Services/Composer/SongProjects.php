<?php

namespace App\Services\Composer;

use App\Enums\Feature;
use App\Models\AiJob;
use App\Models\Lyric;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\Song;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiStudio;
use App\Services\Ai\Credits;
use App\Services\Artists\ArtistVoices;
use App\Services\Limits;
use App\Services\Lyrics\LyricAligner;
use App\Services\Lyrics\Lyrics;
use App\Services\Reputation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a Composer song into a project track carrying its lyric sheet, on a
 * new project or one the user can edit, and can have AI sing the lyrics
 * onto that track. The writer's own song stays linked to its track; a
 * published song used by someone else is copied onto theirs.
 */
class SongProjects
{
    /** Song length asked for when the model takes one and the writer gave none */
    public const DEFAULT_SECONDS = 180;

    public function __construct(
        protected Lyrics $lyrics,
        protected LyricAligner $aligner,
        protected AiStudio $studio,
        protected ArtistVoices $voices,
    ) {}

    /**
     * Create the track, on `$project` when given or else on a new project.
     * With `generate`, AI makes a song from the lyrics on the track; that is
     * checked before anything is created.
     *
     * @param  array{title?: ?string, privacy?: ?string, genre_id: string, artist_id: string, generate?: bool, prompt?: ?string, seconds?: ?int, voice_artist_id?: ?int}  $data
     * @return array{track: ProjectTrack, job: ?AiJob}
     */
    public function create(Song $song, User $user, array $data, ?Project $project = null): array
    {
        $writer = $song->user_id === $user->id;

        if ($writer && $song->track) {
            throw ValidationException::withMessages(['song' => 'This composition already has a project. Update its lyric sheet instead.']);
        }

        if ($song->lyricLines() === []) {
            throw ValidationException::withMessages(['song' => 'Write some lyrics before making a project from this composition.']);
        }

        if (! $project) {
            Limits::for($user)->ensureBelow(
                Feature::MAX_PROJECTS,
                $user->projects()->count(),
                'title',
                'Your account can have up to :limit projects.'
            );
        }

        $input = ! empty($data['generate']) ? $this->musicInput($song, $user, $data) : null;
        $title = trim((string) ($data['title'] ?? '')) ?: $song->title;

        $track = DB::transaction(function () use ($song, $user, $data, $title, $project, $writer) {
            $project ??= $user
                ->projects()
                ->create([
                    'title' => $title,
                    'privacy' => $data['privacy'] ?? 'private',
                    'description' => $writer ? null : "Lyrics from \"{$song->title}\" by {$song->user?->fullname}.",
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

            if ($writer) {
                $song
                    ->forceFill(['project_track_id' => $track->id])
                    ->saveQuietly();
            }

            return $track;
        });

        $action = match (true) {
            $project !== null => 'create_project_track',
            $user->projects()->count() > 1 => 'create_project',
            default => 'first_project',
        };

        (new Reputation($user, $action))->add();

        $job = $input ? $this->studio->start($user, $track, 'music', $input) : null;

        return ['track' => $track, 'job' => $job];
    }

    /**
     * Have AI sing the song's lyrics onto the track it already has.
     *
     * @param  array{prompt?: ?string, seconds?: ?int, voice_artist_id?: ?int}  $data
     */
    public function generate(Song $song, User $user, array $data): AiJob
    {
        if (! $song->track) {
            throw ValidationException::withMessages(['song' => 'This composition has no project yet.']);
        }

        $input = $this->musicInput($song, $user, $data);

        return $this->studio->start($user, $song->track, 'music', $input);
    }

    /**
     * Put the song's current lyrics on its track's lyric sheet, keeping the
     * times of lines that are still there. Released tracks follow the sheet.
     */
    public function syncLyrics(Song $song, User $user): ?Lyric
    {
        $track = $song->track;

        if (! $track) {
            throw ValidationException::withMessages(['song' => 'This composition has no project yet.']);
        }

        $before = $this->lyrics->for($track)?->lines ?? [];
        $lines = $this->aligner->keepTimes($before, $song->lyricLines());

        return $this->lyrics->save($track, $lines, Lyric::WRITTEN, $user, ['language' => $song->editorSettings()['language']]);
    }

    /**
     * The AI music job's input for the song, checked against the Composer
     * song model's limits and the writer's access and credits. A voice
     * artist sings it in that artist's voice.
     *
     * @param  array{prompt?: ?string, seconds?: ?int, voice_artist_id?: ?int, vocal_gender?: ?string}  $data
     * @return array<string, mixed>
     */
    protected function musicInput(Song $song, User $user, array $data): array
    {
        $model = AiSettings::composerMusicModel();
        $credits = Credits::for($user);
        $credits->ensureAccess('generate');

        if (! $model->engine()->available()) {
            throw ValidationException::withMessages(['generate' => "AI singing isn't available right now."]);
        }

        $lyrics = trim((string) $song->body);
        [$min, $max] = $model->lyricsLength() ?? [1, 0];
        [$promptMin, $promptMax] = $model->promptLength();
        $prompt = trim((string) ($data['prompt'] ?? ''));

        if (mb_strlen($lyrics) < $min || mb_strlen($lyrics) > $max) {
            throw ValidationException::withMessages(['generate' => "AI sings lyrics of {$min} to {$max} characters. This composition has " . mb_strlen($lyrics) . '.']);
        }

        if (mb_strlen($prompt) < $promptMin || mb_strlen($prompt) > $promptMax) {
            throw ValidationException::withMessages(['prompt' => "Describe the sound in {$promptMin} to {$promptMax} characters."]);
        }

        $seconds = $model->maxSeconds()
            ? min(max((int) ($data['seconds'] ?? self::DEFAULT_SECONDS), $model->minSeconds()), $model->maxSeconds(), AiSettings::musicMaxSeconds())
            : null;
        $cost = $model->credits((int) $seconds);
        $voice = ! empty($data['voice_artist_id']) ? $this->voices->forMusic($user, $data['voice_artist_id'], $model) : null;

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
            'voice_artist_id' => $voice?->artist_id,
            'vocal_gender' => in_array($data['vocal_gender'] ?? null, ['m', 'f'], true) ? $data['vocal_gender'] : null,
            ...$model->jobSettings(),
        ], fn($value) => $value !== null);
    }
}
