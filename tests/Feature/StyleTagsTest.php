<?php

namespace Tests\Feature;

use App\Enums\Mood;
use App\Jobs\FingerprintTrack;
use App\Jobs\ProcessAudio;
use App\Jobs\TagAudio;
use App\Jobs\TagStyle;
use App\Jobs\TranscribeTrack;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Lyric;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Audio\StyleTagger;
use App\Services\Composer\Ai\LyricModel;
use App\Services\Lyrics\Lyrics;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StyleTagsTest extends TestCase
{
    use DatabaseTransactions;

    protected const API = 'https://replicate.test/v1';

    protected string $dir;

    protected User $owner;

    protected Genre $chosen;

    protected Genre $heard;

    /** What the fake model answers for moods */
    protected array $moodScores = ['happy' => 0.5, 'playful' => 0.25, 'calm' => 0.1];

    /** What the fake lyric model answers */
    protected string $lyricAnswer = '';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Sleep::fake();
        Bus::fake([TagAudio::class, TagStyle::class, TranscribeTrack::class, FingerprintTrack::class, ProcessAudio::class]);
        config(['ai.providers.replicate.key' => 'r8_test', 'ai.providers.replicate.url' => self::API]);
        AiSettings::update(['ai_styles_auto' => true, 'ai_styles_model' => 'toneflix/style-tagger', 'ai_styles_lyrics' => true]);

        $this->dir = sys_get_temp_dir() . '/style-tags-' . Str::random(8);
        mkdir($this->dir);
        $this->owner = User::factory()
            ->create();
        $this->chosen = Genre::factory()
            ->create(['title' => 'Chosen ' . Str::random(6)]);
        $this->heard = Genre::factory()
            ->create(['title' => 'Heard ' . Str::random(6), 'prompt' => 'This is a test genre song.']);

        $this->fakeReplicate();
        $this->app->instance(LyricModel::class, new class($this) implements LyricModel
        {
            public array $asked = [];

            public function __construct(protected StyleTagsTest $test) {}

            public function ask(string $system, string $prompt, int $maxTokens = 1200): string
            {
                $this->asked[] = $prompt;

                return $this->test->lyricAnswer();
            }

            public function name(): string
            {
                return 'fake';
            }

            public function available(): bool
            {
                return true;
            }
        });
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
        AiSettings::flush();

        parent::tearDown();
    }

    public function lyricAnswer(): string
    {
        return $this->lyricAnswer;
    }

    /**
     * Replicate takes the excerpt and scores the heard genre highest.
     */
    protected function fakeReplicate(): void
    {
        Http::fake([
            self::API . '/files' => Http::response(['urls' => ['get' => self::API . '/files/excerpt']]),
            self::API . '/models/toneflix/style-tagger/predictions' => function (Request $request) {
                $labels = json_decode($request['input']['labels'], true);
                $genres = collect($labels['genre'])
                    ->map(fn($prompt, $id) => (int) $id === $this->heard->id ? 0.6 : 0.4 / max(1, count($labels['genre']) - 1));
                $moods = collect($labels['mood'])
                    ->map(fn($prompt, $mood) => $this->moodScores[$mood] ?? 0.01);

                return Http::response([
                    'id' => 'p1',
                    'status' => 'succeeded',
                    'output' => ['genre' => $genres->all(), 'mood' => $moods->all()],
                    'error' => null,
                ]);
            },
        ]);
    }

    protected function song(): string
    {
        $path = "{$this->dir}/song.mp3";

        (new Process([
            'ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi',
            '-i', 'sine=frequency=440:duration=20', '-ac', '1', '-b:a', '64k', $path,
        ]))->mustRun();

        $name = Str::random(12) . '.mp3';
        Storage::put('public/media/music/tracks/' . $name, file_get_contents($path));

        return $name;
    }

    protected function release(array $track = [], bool $draft = false): Track
    {
        $release = Release::factory()
            ->create([
                'draft' => $draft,
                'price' => 0,
                'genre_id' => $this->chosen->id,
                'user_id' => $this->owner->id,
                'artist_id' => Artist::factory()->create()->id,
            ]);

        return Track::factory()
            ->create(['release_id' => $release->id, 'genre_id' => $this->chosen->id, 'audio' => null, 'duration' => 20, ...$track]);
    }

    protected function projectTrack(): ProjectTrack
    {
        $project = Project::query()
            ->forceCreate(['user_id' => $this->owner->id, 'title' => 'Styles test', 'privacy' => 'private']);

        return $project
            ->tracks()
            ->forceCreate(['title' => 'Night Run', 'privacy' => 'private', 'genre_id' => $this->chosen->id]);
    }

    protected function tag(Track|ProjectTrack $owner, bool $inherit = false, bool $lyrics = false): void
    {
        app()
            ->call([new TagStyle($owner, $inherit, $lyrics), 'handle']);
    }

    /**
     * A faked job never runs, so its unique lock has to be let go by hand.
     */
    protected function unlock(Track|ProjectTrack $owner): void
    {
        (new UniqueLock(Cache::driver()))->release(new TagStyle($owner));
    }

    public function test_uploaded_tracks_get_moods_and_a_suggested_genre(): void
    {
        $track = $this->release(['audio' => $this->song()]);

        Bus::assertDispatched(TagStyle::class, fn(TagStyle $job) => $job->owner->is($track) && ! $job->inherit);
        $this->tag($track);

        $track->refresh();
        $this->assertSame(['happy', 'playful'], $track->moods);
        $this->assertSame(Track::TAGS_DETECTED, $track->moods_source);
        $this->assertSame($this->heard->id, $track->suggested_genre_id);
        $this->assertSame($this->chosen->id, $track->genre_id);

        Http::assertSent(function (Request $request) {
            if (! str_ends_with($request->url(), '/predictions')) {
                return false;
            }

            $labels = json_decode($request['input']['labels'], true);

            return $labels['genre'][$this->heard->id] === 'This is a test genre song.'
                && $labels['mood']['happy'] === Mood::HAPPY->prompt()
                && $request['input']['audio'] === self::API . '/files/excerpt';
        });

        $this->getJson("/api/tracks/{$track->id}")
            ->assertOk()
            ->assertJsonPath('data.styles.moods', ['happy', 'playful'])
            ->assertJsonPath('data.styles.suggestedGenreId', $this->heard->id);
    }

    public function test_unsure_genres_are_not_suggested_and_weak_moods_are_left_out(): void
    {
        $tagger = app(StyleTagger::class);

        $this->assertNull($tagger->genre(['genre' => [4 => 0.25, 9 => 0.2]]));
        $this->assertNull($tagger->genre(['genre' => [4 => 0.45, 9 => 0.2]]));
        $this->assertSame(4, $tagger->genre(['genre' => [9 => 0.2, 4 => 0.55]]));
        $this->assertSame([], $tagger->moods(['mood' => ['happy' => 0.1, 'sad' => 0.12]]));
        $this->assertSame(
            ['sad', 'happy', 'calm'],
            $tagger->moods(['mood' => ['happy' => 0.3, 'sad' => 0.4, 'calm' => 0.2, 'dark' => 0.16]]),
        );
    }

    public function test_lyrics_add_their_moods(): void
    {
        $this->lyricAnswer = 'Sad, romantic.';
        $this->moodScores = ['happy' => 0.4, 'sad' => 0.2, 'calm' => 0.2];
        $track = $this->release(['audio' => $this->song()]);
        Lyric::query()
            ->forceCreate([
                'lyricable_type' => $track->getMorphClass(),
                'lyricable_id' => $track->id,
                'lines' => [['start' => null, 'end' => null, 'text' => 'I miss you every night']],
                'text' => 'I miss you every night',
                'source' => Lyric::WRITTEN,
            ]);

        $this->tag($track);

        // Audio 0.7 × score plus lyrics 0.3 × ½ each: sad 0.29, happy 0.28, romantic 0.15, calm 0.14.
        $track->refresh();
        $this->assertSame(['sad', 'happy', 'romantic'], $track->moods);
        $this->assertSame(['sad', 'romantic'], $track->style_scores['lyrics']);
    }

    public function test_new_lyrics_are_read_again_without_listening_again(): void
    {
        $track = $this->release(['audio' => $this->song()]);
        $this->tag($track);
        $this->unlock($track);
        Http::fake(fn() => throw new RuntimeException('Should not call Replicate.'));

        $this->lyricAnswer = 'dark';
        app(Lyrics::class)->save($track->refresh(), [['text' => 'Shadows on the wall']], Lyric::WRITTEN);

        Bus::assertDispatched(TagStyle::class, fn(TagStyle $job) => $job->owner->is($track) && $job->lyrics);
        $this->tag($track, lyrics: true);

        $this->assertSame(['happy', 'dark', 'playful'], $track->fresh()->moods);
    }

    public function test_creators_set_moods_and_detection_keeps_them(): void
    {
        $track = $this->release(['audio' => $this->song()]);
        $this->tag($track);

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/creator/tracks/{$track->id}/moods", ['moods' => ['dark', 'tense']])
            ->assertAccepted()
            ->assertJsonPath('data.moods', ['dark', 'tense'])
            ->assertJsonPath('data.source', 'manual');

        $this->tag($track->refresh());
        $this->assertSame(['dark', 'tense'], $track->fresh()->moods);

        $this->putJson("/api/creator/tracks/{$track->id}/moods", ['moods' => ['gloomy']])
            ->assertUnprocessable();
        $this->putJson("/api/creator/tracks/{$track->id}/moods", ['moods' => ['dark', 'tense', 'sad', 'epic']])
            ->assertUnprocessable();

        // Sending none goes back to what was heard.
        $this->putJson("/api/creator/tracks/{$track->id}/moods", ['moods' => []])
            ->assertAccepted()
            ->assertJsonPath('data.moods', ['happy', 'playful'])
            ->assertJsonPath('data.source', 'detected');

        Sanctum::actingAs(User::factory()->create());
        $this->putJson("/api/creator/tracks/{$track->id}/moods", ['moods' => ['dark']])
            ->assertNotFound();
    }

    public function test_project_tracks_pass_moods_to_draft_releases(): void
    {
        $projectTrack = $this->projectTrack();
        $projectTrack->preview = $this->song();
        $projectTrack->saveQuietly();
        $draft = $this->release(['project_track_id' => $projectTrack->id], draft: true);
        $published = $this->release(['project_track_id' => $projectTrack->id]);

        $this->tag($projectTrack);

        $this->assertSame(['happy', 'playful'], $projectTrack->fresh()->moods);
        $this->assertSame(['happy', 'playful'], $draft->fresh()->moods);
        $this->assertNull($published->fresh()->moods);

        // A released track without its own audio takes the project's tags without listening.
        Http::fake(fn() => throw new RuntimeException('Should not call Replicate.'));
        $this->tag($published, inherit: true);
        $this->assertSame($this->heard->id, $published->fresh()->suggested_genre_id);

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/creator/projects/{$projectTrack->project_id}/tracks/{$projectTrack->id}/moods", ['moods' => ['epic']])
            ->assertAccepted();
        $this->assertSame(['epic'], $draft->fresh()->moods);
    }

    public function test_tracks_filter_by_mood(): void
    {
        $happy = $this->release();
        $happy->forceFill(['moods' => ['happy', 'groovy']])->saveQuietly();
        $dark = $this->release();
        $dark->forceFill(['moods' => ['dark']])->saveQuietly();

        $ids = collect($this->getJson('/api/tracks?mood=groovy,calm&limit=100')->assertOk()->json('data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($happy->id));
        $this->assertFalse($ids->contains($dark->id));
    }

    public function test_detection_waits_for_the_model_setting(): void
    {
        AiSettings::update(['ai_styles_model' => null]);
        $track = $this->release(['audio' => $this->song()]);

        $this->tag($track);

        $this->assertNull($track->fresh()->styled_at);
        Http::assertNothingSent();
        $this->artisan('tags:styles')
            ->assertFailed();
    }

    public function test_admins_set_the_model_and_genre_prompts(): void
    {
        $admin = User::factory()
            ->create();
        $admin->givePermissionTo(
            Permission::findOrCreate('manage-configuration', 'web'),
            Permission::findOrCreate('manage-content', 'web'),
        );
        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/ai', ['ai_styles_model' => 'not a model'])
            ->assertUnprocessable();

        $this->putJson("/api/admin/genres/{$this->heard->id}", ['title' => $this->heard->title, 'prompt' => 'This is a song with talking drums.'])
            ->assertAccepted()
            ->assertJsonPath('data.prompt', 'This is a song with talking drums.');
        $this->assertSame('This is a song with talking drums.', $this->heard->fresh()->stylePrompt());
        $this->assertSame("This is a {$this->chosen->title} song.", $this->chosen->stylePrompt());
    }
}
