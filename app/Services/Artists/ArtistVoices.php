<?php

namespace App\Services\Artists;

use App\Enums\AiEngine;
use App\Enums\HttpStatus;
use App\Jobs\BuildArtistVoice;
use App\Jobs\PrepareVoiceCheck;
use App\Models\AiJob;
use App\Models\Artist;
use App\Models\ArtistVoice;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiStudio;
use App\Services\Ai\Music\MusicModel;
use App\Services\Ai\Music\Song;
use App\Services\Ai\Suno\SunoClient;
use App\Services\Audio\StemAudio;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Artists' voices for AI music: samples the AI makes or the artist's account
 * uploads, the voices music engines build from them, and which voice a music
 * job sings with.
 */
class ArtistVoices
{
    /** Length of a sample the AI makes, in seconds */
    public const SAMPLE_SECONDS = 20;

    /** Shortest sample people can upload, in seconds */
    public const MIN_SECONDS = 20;

    /** Longest part of a sample that is kept, in seconds */
    public const MAX_SECONDS = 60;

    /** The longest part of a sample an engine builds a voice from */
    protected const BUILD_SECONDS = 30;

    /** The shortest sample Suno builds a voice from */
    protected const PERSONA_MIN_SECONDS = 10;

    protected const DIRECTORY = 'artists/voices';

    protected const SAMPLE_LYRICS = "[Verse]\nI walk the road where the morning starts\nSinging softly with an open heart\nEvery step a little brighter now\nI keep on going, I will find my way somehow";

    public function __construct(
        protected StemAudio $audio,
        protected AiStudio $studio,
    ) {}

    /**
     * The artist's voice, new and unsaved when it has none.
     */
    public function for(Artist $artist): ArtistVoice
    {
        return $artist->voice ?? $artist
            ->voice()
            ->make();
    }

    /**
     * Set the words music prompts use for the artist's voice.
     */
    public function describe(Artist $artist, ?string $description): ArtistVoice
    {
        $voice = $this->for($artist);
        $voice->description = filled($description) ? Str::limit(trim($description), 300, '') : null;
        $voice->save();

        return $voice;
    }

    /**
     * Keep a sample the artist's account uploaded or recorded. When Suno is
     * set up, it starts writing the phrase the owner records to prove the
     * voice is theirs.
     */
    public function upload(Artist $artist, UploadedFile $file): ArtistVoice
    {
        $seconds = $this->audio->probe($file->getRealPath())['duration'];

        if ($seconds < self::MIN_SECONDS) {
            throw ValidationException::withMessages([
                'sample' => 'The sample needs to be at least ' . self::MIN_SECONDS . ' seconds long.',
            ]);
        }

        $voice = $this->for($artist);
        $this->keepSample($voice, $file->getRealPath(), min($seconds, self::MAX_SECONDS), ArtistVoice::UPLOADED);

        if (AiEngine::SUNO->available()) {
            $this->restartCheck($voice);
        }

        return $voice;
    }

    /**
     * Start an AI job that makes a sample for the artist, paid by the user.
     */
    public function generate(Artist $artist, User $user): AiJob
    {
        $model = AiSettings::composerMusicModel();

        if (! $model->takesVoices()) {
            throw ValidationException::withMessages(['voice' => 'AI voice samples aren\'t available right now.']);
        }

        $voice = $this->for($artist);

        if ($voice->status === ArtistVoice::GENERATING) {
            throw ValidationException::withMessages(['voice' => 'A voice sample is already being made.']);
        }

        [, $max] = $model->promptLength();
        $prompt = trim('A cappella, one solo singer, no instruments, no beat, no backing vocals. ' . $voice->description);

        $before = $voice->status;
        $voice->forceFill(['status' => ArtistVoice::GENERATING, 'failure' => null]);
        $voice->save();

        try {
            $job = $this->studio->start($user, null, 'voice', array_filter([
                'artist_id' => $artist->id,
                'artist' => $artist->name,
                'model' => $model::ID,
                'prompt' => mb_substr($prompt, 0, $max),
                'lyrics' => self::SAMPLE_LYRICS,
                'seconds' => $model->maxSeconds() ? max(self::SAMPLE_SECONDS, $model->minSeconds()) : null,
                'instrumental' => false,
                'vocal_gender' => $voice->gender(),
                ...$model->jobSettings(),
            ], fn($value) => $value !== null));
        } catch (Throwable $e) {
            $voice->forceFill(['status' => $before]);
            $voice->save();

            throw $e;
        }

        $voice->ai_job_id = $job->id;
        $voice->save();

        return $job;
    }

    /**
     * Keep the sample an AI job made. A Suno song also becomes a voice Suno
     * reuses for the artist's later songs.
     *
     * @return array{seconds: float, persona: bool, notice?: string}
     */
    public function keepGenerated(ArtistVoice $voice, Song $song): array
    {
        $seconds = min($this->audio->probe($song->path)['duration'], self::MAX_SECONDS);
        $this->keepSample($voice, $song->path, $seconds, ArtistVoice::GENERATED);

        $suno = $song->meta['suno'] ?? null;

        if (! $suno || $seconds < self::PERSONA_MIN_SECONDS) {
            return ['seconds' => $seconds, 'persona' => false];
        }

        try {
            $persona = SunoClient::make()
                ->persona(
                    (string) $suno['task'],
                    (string) $suno['audio'],
                    $voice->artist->name,
                    $voice->description ?: "The singing voice of {$voice->artist->name}",
                    0,
                    min($seconds, self::BUILD_SECONDS),
                );
        } catch (Throwable $e) {
            Log::error('Artist voice: Suno persona failed', ['artist' => $voice->artist_id, 'error' => $e->getMessage()]);

            return ['seconds' => $seconds, 'persona' => false, 'notice' => 'The sample was kept, but its voice couldn\'t be built.'];
        }

        $voice->forceFill(['persona_id' => $persona, 'persona_kind' => ArtistVoice::STYLE_PERSONA]);
        $voice->save();

        return ['seconds' => $seconds, 'persona' => true];
    }

    /**
     * Note that the AI couldn't make the artist's sample.
     */
    public function generationFailed(ArtistVoice $voice, string $reason): void
    {
        $voice->forceFill([
            'status' => $voice->file ? ArtistVoice::READY : ArtistVoice::FAILED,
            'failure' => $reason,
        ]);
        $voice->save();
    }

    /**
     * Ask Suno for a new phrase for the owner to record.
     */
    public function restartCheck(ArtistVoice $voice): ArtistVoice
    {
        if (! $voice->hasSample() || $voice->source !== ArtistVoice::UPLOADED) {
            throw ValidationException::withMessages(['sample' => 'Upload or record your voice first.']);
        }

        $voice->forceFill([
            'check_status' => ArtistVoice::CHECK_PREPARING,
            'check_task' => null,
            'check_phrase' => null,
            'failure' => null,
        ]);
        $voice->save();

        PrepareVoiceCheck::dispatch($voice)
            ->afterCommit();

        return $voice;
    }

    /**
     * Have Suno write the phrase for the owner to record. Runs on the queue.
     */
    public function prepareCheck(ArtistVoice $voice): void
    {
        $suno = SunoClient::make();
        $path = $this->local($voice);

        try {
            $url = $suno->upload($path, 'audio/mpeg');
            $task = $suno->startVoiceCheck($url, 0, (int) min(floor((float) $voice->seconds), self::BUILD_SECONDS));
            $phrase = $suno->voicePhrase($task);
        } catch (Throwable $e) {
            Log::error('Artist voice: Suno phrase failed', ['artist' => $voice->artist_id, 'error' => $e->getMessage()]);
            $this->checkFailed($voice, 'The voice check couldn\'t start. Try again.');

            return;
        } finally {
            @unlink($path);
        }

        $voice->forceFill(['check_status' => ArtistVoice::CHECK_RECORDING, 'check_task' => $task, 'check_phrase' => $phrase]);
        $voice->save();
    }

    /**
     * Take the owner's recording of the phrase and have Suno build the voice.
     */
    public function submitCheck(Artist $artist, UploadedFile $recording): ArtistVoice
    {
        $voice = $this->for($artist);

        if ($voice->check_status !== ArtistVoice::CHECK_RECORDING || ! $voice->check_task) {
            throw ValidationException::withMessages(['recording' => 'There is no phrase to record right now.']);
        }

        $mp3 = $this->audio->mp3($recording->getRealPath());
        $file = self::DIRECTORY . '/checks/' . Str::random(40) . '.mp3';

        try {
            $this
                ->disk()
                ->put($file, fopen($mp3, 'r'));
        } finally {
            @unlink($mp3);
        }

        $voice->forceFill(['check_status' => ArtistVoice::CHECK_BUILDING, 'failure' => null]);
        $voice->save();

        BuildArtistVoice::dispatch($voice, $file)
            ->afterCommit();

        return $voice;
    }

    /**
     * Have Suno check the recording and build the voice. Runs on the queue.
     */
    public function buildVoice(ArtistVoice $voice, string $file): void
    {
        $suno = SunoClient::make();
        $path = $this->copy($file);

        try {
            $url = $suno->upload($path, 'audio/mpeg');
            $id = $suno->createVoice((string) $voice->check_task, $url, $voice->artist->name, $voice->description);
        } catch (Throwable $e) {
            Log::error('Artist voice: Suno voice failed', ['artist' => $voice->artist_id, 'error' => $e->getMessage()]);
            $this->checkFailed($voice, 'Your recording didn\'t pass the voice check. Try again with a new phrase.');

            return;
        } finally {
            @unlink($path);
            $this
                ->disk()
                ->delete($file);
        }

        $voice->forceFill([
            'persona_id' => $id,
            'persona_kind' => ArtistVoice::VOICE_PERSONA,
            'check_status' => ArtistVoice::CHECK_DONE,
            'check_phrase' => null,
        ]);
        $voice->save();
    }

    /**
     * Remove the artist's sample and the voices built from it, keeping the
     * description.
     */
    public function forgetSample(Artist $artist): ArtistVoice
    {
        $voice = $this->for($artist);

        if ($voice->file) {
            $this
                ->disk()
                ->delete($voice->file);
        }

        $voice->forceFill([
            'status' => ArtistVoice::EMPTY,
            'source' => null,
            'file' => null,
            'seconds' => null,
            'ai_job_id' => null,
            'persona_id' => null,
            'persona_kind' => null,
            'check_status' => null,
            'check_task' => null,
            'check_phrase' => null,
            'failure' => null,
        ]);

        if ($voice->exists || $voice->description) {
            $voice->save();
        }

        return $voice;
    }

    /**
     * The voice of an artist the user manages, checked for singing with the
     * model, for a music job's `voice_artist_id`.
     */
    public function forMusic(User $user, mixed $artistId, MusicModel $model): ArtistVoice
    {
        $artist = Artist::query()->find($artistId);
        $voice = $artist?->isManagedBy($user) ? $artist->voice : null;

        if (! $voice || ! $model->voiceFrom($voice)) {
            throw ValidationException::withMessages(['voice_artist_id' => 'That voice can\'t be used with AI music right now.']);
        }

        return $voice;
    }

    /**
     * The voice a music job sings with, if it still has one.
     *
     * @param  array<string, mixed>  $input
     */
    public function forJob(array $input): ?ArtistVoice
    {
        if (empty($input['voice_artist_id'])) {
            return null;
        }

        return ArtistVoice::query()
            ->where('artist_id', $input['voice_artist_id'])
            ->first();
    }

    /**
     * A local copy of the voice's sample.
     */
    public function local(ArtistVoice $voice): string
    {
        return $this->copy((string) $voice->file);
    }

    /**
     * A link that plays the sample for a few hours without signing in.
     */
    public function url(ArtistVoice $voice): ?string
    {
        return $voice->file
            ? URL::temporarySignedRoute('artists.voice.audio', now()->addHours(6), ['artist' => $voice->artist_id, 'v' => $voice->updated_at?->timestamp])
            : null;
    }

    public function stream(Artist $artist): StreamedResponse
    {
        $file = $artist->voice?->file;

        abort_unless($file && $this->disk()->exists($file), HttpStatus::NOT_FOUND->value, 'This artist has no voice sample.');

        return $this
            ->disk()
            ->response($file, null, ['Content-Type' => 'audio/mpeg', 'Cache-Control' => 'private, max-age=21600']);
    }

    /**
     * Store the first seconds of a local file as the voice's sample, dropping
     * the old sample and any voice built from it.
     */
    protected function keepSample(ArtistVoice $voice, string $path, float $seconds, string $source): void
    {
        $mp3 = $this->audio->cut($path, 0, $seconds);
        $file = self::DIRECTORY . '/' . Str::random(40) . '.mp3';

        try {
            $this
                ->disk()
                ->put($file, fopen($mp3, 'r'));
        } finally {
            @unlink($mp3);
        }

        if ($voice->file) {
            $this
                ->disk()
                ->delete($voice->file);
        }

        $voice->forceFill([
            'status' => ArtistVoice::READY,
            'source' => $source,
            'file' => $file,
            'seconds' => round($seconds, 2),
            'persona_id' => null,
            'persona_kind' => null,
            'check_status' => null,
            'check_task' => null,
            'check_phrase' => null,
            'failure' => null,
        ]);
        $voice->save();
    }

    protected function checkFailed(ArtistVoice $voice, string $reason): void
    {
        $voice->forceFill(['check_status' => ArtistVoice::CHECK_FAILED, 'failure' => $reason]);
        $voice->save();
    }

    protected function copy(string $file): string
    {
        $path = sys_get_temp_dir() . '/voice-' . Str::random(16) . '.mp3';
        file_put_contents($path, $this->disk()->readStream($file));

        return $path;
    }

    protected function disk(): FilesystemAdapter
    {
        return Storage::disk(config('filesystems.default'));
    }
}
