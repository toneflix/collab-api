<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Events\RealtimeMessage;
use App\Jobs\AdvanceAgentRun;
use App\Jobs\RenderMix;
use App\Jobs\RunAiJob;
use App\Models\AgentRun;
use App\Models\AgentStep;
use App\Models\AiJob;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Lyric;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use App\Models\Release;
use App\Models\Song;
use App\Models\Track;
use App\Models\User;
use App\Services\Agent\Actions\DrawArtwork;
use App\Services\Agent\AgentPlanner;
use App\Services\Agent\ContentAgent;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Credits;
use App\Services\Ai\Music\MusicVersions;
use App\Services\Composer\Ai\LyricModel;
use App\Services\Realtime\RealtimeSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ContentAgentTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string|RuntimeException> */
    public array $answers = [];

    /** @var list<array{system: string, prompt: string, maxTokens: int}> */
    public array $asked = [];

    protected Genre $genre;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['ai.providers.replicate.key' => 'r8_test']);
        AiSettings::update([
            'ai_enabled' => true,
            'ai_music_model' => 'elevenlabs/music',
            'ai_music_credits' => 5,
            'ai_composer_music_model' => 'minimax/music-2.5',
            'ai_music_minimax_25_credits' => 30,
            'ai_composer_verse_credits' => 2,
        ]);

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();
        Permission::findOrCreate('manage-content', 'web');

        $this->genre = Genre::factory()
            ->create(['title' => 'Agent Afrobeats ' . uniqid()]);

        $test = $this;
        $this->app->instance(LyricModel::class, new class($test) implements LyricModel
        {
            public function __construct(protected ContentAgentTest $test) {}

            public function ask(string $system, string $prompt, int $maxTokens = 1200): string
            {
                $this->test->asked[] = ['system' => $system, 'prompt' => $prompt, 'maxTokens' => $maxTokens];
                $answer = array_shift($this->test->answers) ?? json_encode(['lines' => [
                    '[Verse 1]', 'Lagos lights are calling out my name', 'Every street a rhythm, every rhythm a flame',
                    '[Chorus]', 'Hold me close tonight', 'Dance until the morning light',
                ]]);

                if ($answer instanceof RuntimeException) {
                    throw $answer;
                }

                return $answer;
            }

            public function name(): string
            {
                return 'test/model';
            }

            public function available(): bool
            {
                return true;
            }
        });
    }

    protected function tearDown(): void
    {
        AiSettings::flush();
        RealtimeSettings::flush();

        parent::tearDown();
    }

    protected function ownerWith(int $credits, array $features = []): User
    {
        $plan = Plan::create([
            'slug' => 'agent-owner-' . uniqid(), 'name' => 'Agent', 'price' => 0, 'interval' => 'month',
            'features' => [Feature::AI_TOOLS->value => true, Feature::AI_CREDITS->value => $credits, ...$features],
        ]);

        $user = User::factory()
            ->create();
        $user
            ->forceFill(['content_agent' => true])
            ->save();

        $user
            ->subscriptions()
            ->create([
                'plan' => $plan->slug, 'plan_id' => $plan->id, 'amount' => 0,
                'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(),
            ]);

        return $user->fresh();
    }

    protected function admin(): User
    {
        $admin = User::factory()
            ->create();
        $admin->givePermissionTo('manage-content');

        foreach ($this->app['router']->getRoutes() as $route) {
            $route->controller = null;
        }

        Sanctum::actingAs($admin = $admin->fresh());

        return $admin;
    }

    protected function plan(array $overrides = []): string
    {
        return json_encode($overrides + [
            'artists' => [['key' => 'a1', 'name' => 'Ada Lumen', 'about' => 'A singer from Lagos.']],
            'albums' => [[
                'key' => 'al1',
                'title' => 'Night Market',
                'artist' => 'a1',
                'genre' => $this->genre->title,
                'description' => 'Warm late night grooves.',
                'tracks' => [
                    ['title' => 'Lagos Lights', 'style' => 'Afrobeats, 104 BPM, log drums, warm female vocals', 'theme' => 'Dancing in the city', 'mood' => 'joyful', 'instrumental' => false],
                    ['title' => 'Harbour Breeze', 'style' => 'Mellow instrumental afrobeats with guitar', 'theme' => '', 'instrumental' => true],
                ],
            ]],
            'songs' => [['title' => 'Paper Boats', 'theme' => 'Childhood by the sea', 'style' => 'acoustic', 'mood' => 'tender']],
        ]);
    }

    protected function queueRun(User $owner, array $options = []): AgentRun
    {
        $admin = User::factory()
            ->create();

        return app(ContentAgent::class)->queue($admin, $owner, 'Make one Afrobeats artist with a two track album, and one written song.', $options + [
            'publish' => false,
            'artwork' => false,
            'max_tracks' => 12,
            'seconds' => 60,
        ]);
    }

    protected function advance(AgentRun $run): AgentRun
    {
        app(ContentAgent::class)->advance($run);

        return $run->refresh();
    }

    protected function step(AgentRun $run, string $key): AgentStep
    {
        return $run
            ->steps()
            ->where('key', $key)
            ->firstOrFail();
    }

    public function test_admins_queue_prompts_and_others_cannot(): void
    {
        $owner = $this->ownerWith(200);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/admin/agent/runs', ['prompt' => 'Make an afrobeats album please', 'owner_id' => $owner->id])
            ->assertForbidden();

        $this->admin();

        $this->postJson('/api/admin/agent/runs', ['prompt' => 'Make an afrobeats album please', 'owner_id' => $owner->id, 'max_tracks' => 4])
            ->assertCreated()
            ->assertJsonPath('data.status', AgentRun::PLANNING)
            ->assertJsonPath('data.owner.id', $owner->id)
            ->assertJsonPath('data.options.maxTracks', 4);

        $this->postJson('/api/admin/agent/runs', ['prompt' => 'A second prompt waits its turn', 'owner_id' => $owner->id])
            ->assertCreated()
            ->assertJsonPath('data.status', AgentRun::QUEUED);

        Queue::assertPushed(AdvanceAgentRun::class, 1);

        $this->getJson('/api/admin/agent/runs')
            ->assertOk()
            ->assertJsonPath('agent.available', true)
            ->assertJsonPath('meta.total', AgentRun::query()->count());
    }

    public function test_an_owner_without_ai_tools_cannot_be_used(): void
    {
        $owner = User::factory()
            ->create();
        $owner
            ->forceFill(['content_agent' => true])
            ->save();
        $this->admin();

        $this->postJson('/api/admin/agent/runs', ['prompt' => 'Make an afrobeats album please', 'owner_id' => $owner->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('owner_id');
    }

    public function test_a_run_plans_and_builds_artists_songs_tracks_and_an_album(): void
    {
        $owner = $this->ownerWith(200);
        $this->answers[] = $this->plan();

        $run = $this->advance($this->queueRun($owner));

        $this->assertSame(AgentRun::RUNNING, $run->status);
        $this->assertSame(4000, $this->asked[0]['maxTokens']);
        $this->assertStringContainsString($this->genre->title, $this->asked[0]['prompt']);
        $this->assertSame(['artists' => 1, 'albums' => 1, 'tracks' => 2], [
            'artists' => count($run->plan['artists']),
            'albums' => count($run->plan['albums']),
            'tracks' => count($run->plan['albums'][0]['tracks']),
        ]);

        // The first pass makes the artist, project, songs and tracks, and starts the music.
        $artist = Artist::query()->findOrFail($this->step($run, 'artist:a1')->output['artist_id']);
        $this->assertSame('Ada Lumen', $artist->name);
        $this->assertSame($owner->id, $artist->user_id);

        $song = Song::query()->findOrFail($this->step($run, 'song:t1')->output['song_id']);
        $this->assertSame('Lagos Lights', $song->title);
        $this->assertStringContainsString("[Chorus]\nHold me close tonight", $song->body);
        $this->assertNotNull($song->track);
        $this->assertSame($artist->id, (int) $song->track->artist_id);
        $this->assertTrue(Lyric::query()->whereMorphedTo('lyricable', $song->track)->exists());

        $written = Song::query()->findOrFail($this->step($run, 'song:s1')->output['song_id']);
        $this->assertNull($written->project_track_id);

        $instrumental = ProjectTrack::query()->findOrFail($this->step($run, 'track:t2')->output['project_track_id']);
        $this->assertSame($song->track->project_id, $instrumental->project_id);

        $vocalJob = AiJob::query()->findOrFail($this->step($run, 'music:t1')->output['ai_job_id']);
        $this->assertSame('minimax/music-2.5', $vocalJob->input['model']);
        $this->assertStringContainsString('Hold me close tonight', $vocalJob->input['lyrics']);

        $instrumentalJob = AiJob::query()->findOrFail($this->step($run, 'music:t2')->output['ai_job_id']);
        $this->assertSame('elevenlabs/music', $instrumentalJob->input['model']);
        $this->assertTrue($instrumentalJob->input['instrumental']);
        $this->assertSame(60, $instrumentalJob->input['seconds']);
        Queue::assertPushed(RunAiJob::class, 2);

        // Music done: the tracks are mixed and the sung track's lyrics are timed.
        $stem = ProjectTrackStem::factory()
            ->create(['track_id' => $song->track->id, 'user_id' => $owner->id, 'title' => 'Lagos Lights', 'src' => 'song.mp3']);

        $vocalJob
            ->forceFill(['status' => AiJob::SUCCEEDED, 'output' => ['stems' => [$stem->id]]])
            ->save();
        $instrumentalJob
            ->forceFill(['status' => AiJob::SUCCEEDED, 'output' => ['stems' => [1]]])
            ->save();

        $run = $this->advance($run);

        $this->assertSame(AgentStep::WAITING, $this->step($run, 'mix:t1')->status);
        Queue::assertPushed(RenderMix::class, 2);
        $this->assertFalse($run->steps()->where('key', 'lyrics:t2')->exists());
        $this->assertContains('lyrics:t1', $this->step($run, 'release:al1')->depends_on);

        $syncJob = AiJob::query()->findOrFail($this->step($run, 'lyrics:t1')->output['ai_job_id']);
        $this->assertSame('lyrics', $syncJob->kind);
        $this->assertSame('sync', $syncJob->input['mode']);
        $this->assertSame($stem->id, (int) $syncJob->input['stem_id']);
        $syncJob
            ->forceFill(['status' => AiJob::SUCCEEDED, 'credits' => 2, 'output' => ['lines' => 4]])
            ->save();

        foreach ([$song->track, $instrumental] as $track) {
            $track->refresh();
            $track->meta = [...collect($track->meta)->all(), 'mix' => ['status' => 'ready', 'id' => 'x']];
            $track->saveQuietly();
        }

        // Mixed: the album is released as a draft.
        $run = $this->advance($run);

        $this->assertSame(AgentRun::SUCCEEDED, $run->status);

        $release = Release::query()
            ->withoutGlobalScopes()
            ->findOrFail($this->step($run, 'release:al1')->output['release_id']);
        $this->assertSame('Night Market', $release->title);
        $this->assertTrue($release->draft);
        $this->assertSame($owner->id, $release->user_id);
        $this->assertSame($artist->id, (int) $release->artist_id);
        $this->assertSame(
            [$song->track->id, $instrumental->id],
            $release->tracks()->orderBy('id')->pluck('project_track_id')->map(fn($id) => (int) $id)->all(),
        );

        $this->assertTrue($this->step($run, 'lyrics:t1')->output['synced']);

        // Plan 2 + two lyrics at 2 + vocal 30 + instrumental 60s at 5 per 10s + timing 2.
        $this->assertSame(68, $run->credits);
    }

    public function test_a_run_makes_tracks_that_feature_other_artists(): void
    {
        $owner = $this->ownerWith(200);
        $this->answers[] = $this->plan([
            'songs' => [],
            'artists' => [
                ['key' => 'a1', 'name' => 'Ada Lumen', 'about' => 'A singer from Lagos.'],
                ['key' => 'a2', 'name' => 'Daniel Okoro', 'about' => 'A rapper from Abuja.'],
            ],
            'albums' => [[
                'key' => 'al1',
                'title' => 'Two Cities',
                'artist' => 'a1',
                'genre' => $this->genre->title,
                'tracks' => [
                    ['title' => 'Bridges', 'style' => 'Afrobeats duet, female and male vocals', 'theme' => 'Two cities', 'featuring' => ['a2', 'a1', 'Daniel Okoro', 'nobody']],
                    ['title' => 'Interlude', 'style' => 'Soft keys', 'instrumental' => true, 'featuring' => ['a2']],
                ],
            ]],
        ]);

        $run = $this->advance($this->queueRun($owner));

        $this->assertStringContainsString('"featuring"', $this->asked[0]['system']);
        $this->assertSame(['a2'], $run->plan['albums'][0]['tracks'][0]['featuring']);
        $this->assertCount(2, $run->plan['artists']);
        $this->assertContains('artist:a2', $this->step($run, 'song:t1')->depends_on);
        $this->assertStringContainsString('Sung by: Ada Lumen featuring Daniel Okoro', $this->asked[1]['prompt']);

        $daniel = (int) $this->step($run, 'artist:a2')->output['artist_id'];
        $song = Song::query()->findOrFail($this->step($run, 'song:t1')->output['song_id']);
        $instrumental = ProjectTrack::query()->findOrFail($this->step($run, 'track:t2')->output['project_track_id']);

        $this->assertSame([$daniel], $song->track->featuredIds());
        $this->assertSame([$daniel], $instrumental->featuredIds());

        $stem = ProjectTrackStem::factory()
            ->create(['track_id' => $song->track->id, 'user_id' => $owner->id, 'title' => 'Bridges', 'src' => 'song.mp3']);

        foreach (['music:t1' => $stem->id, 'music:t2' => 1] as $key => $stemId) {
            AiJob::query()
                ->findOrFail($this->step($run, $key)->output['ai_job_id'])
                ->forceFill(['status' => AiJob::SUCCEEDED, 'output' => ['stems' => [$stemId]]])
                ->save();
        }

        $run = $this->advance($run);

        AiJob::query()
            ->findOrFail($this->step($run, 'lyrics:t1')->output['ai_job_id'])
            ->forceFill(['status' => AiJob::SUCCEEDED, 'output' => ['lines' => 4]])
            ->save();

        foreach ([$song->track, $instrumental] as $track) {
            $track->refresh();
            $track->meta = [...collect($track->meta)->all(), 'mix' => ['status' => 'ready', 'id' => 'x']];
            $track->saveQuietly();
        }

        $run = $this->advance($run);

        $this->assertSame(AgentRun::SUCCEEDED, $run->status);

        $released = Track::query()
            ->where('project_track_id', $song->track->id)
            ->firstOrFail();

        $this->assertSame((int) $this->step($run, 'artist:a1')->output['artist_id'], (int) $released->artist->id);
        $this->assertSame([$daniel], $released->featured()->pluck('artists.id')->map(fn($id) => (int) $id)->all());
    }

    public function test_a_run_draws_album_covers_and_artist_pictures(): void
    {
        Storage::fake(config('filesystems.default'));
        AiSettings::update(['ai_artwork_credits' => 3]);

        $owner = $this->ownerWith(500);
        $this->answers[] = $this->plan([
            'artists' => [['key' => 'a1', 'name' => 'Ada Lumen', 'about' => 'A singer from Lagos.', 'look' => 'Neon portrait at night']],
            'songs' => [],
        ]);

        $run = $this->advance($this->queueRun($owner, ['artwork' => true]));

        $portrait = AiJob::query()->findOrFail($this->step($run, 'portrait:a1')->output['ai_job_id']);
        $cover = AiJob::query()->findOrFail($this->step($run, 'cover:al1')->output['ai_job_id']);
        $this->assertSame('artist', $portrait->input['artwork_kind']);
        $this->assertSame('Neon portrait at night', $portrait->input['prompt']);
        $this->assertSame('release', $cover->input['artwork_kind']);
        $this->assertSame('Ada Lumen', $cover->input['artist']);
        $this->assertSame($this->genre->title, $cover->input['genre']);
        $this->assertSame(1, $cover->input['count']);
        $this->assertContains('cover:al1', $this->step($run, 'release:al1')->depends_on);

        // Three jobs at once: the second track's music waits for a slot.
        $this->assertNull($this->step($run, 'music:t2')->output['ai_job_id'] ?? null);

        foreach ([$portrait, $cover] as $job) {
            $file = "ai/artwork/{$job->id}/1.jpg";

            Storage::disk(config('filesystems.default'))
                ->put($file, UploadedFile::fake()->image('art.jpg', 64, 64)->getContent());
            $job
                ->forceFill(['status' => AiJob::SUCCEEDED, 'credits' => 3, 'output' => ['images' => [['file' => $file]]]])
                ->save();
        }

        $run = $this->advance($run);

        $artist = Artist::query()->findOrFail($this->step($run, 'artist:a1')->output['artist_id']);
        $project = Project::query()->findOrFail($this->step($run, 'project:al1')->output['project_id']);
        $this->assertNotNull($artist->image);
        $this->assertNotNull($project->image);
        $this->assertSame(AgentStep::DONE, $this->step($run, 'cover:al1')->status);

        $vocalJob = AiJob::query()->findOrFail($this->step($run, 'music:t1')->output['ai_job_id']);
        $instrumentalJob = AiJob::query()->findOrFail($this->step($run, 'music:t2')->output['ai_job_id']);

        foreach ([$vocalJob, $instrumentalJob] as $job) {
            $job
                ->forceFill(['status' => AiJob::SUCCEEDED, 'output' => ['stems' => [1]]])
                ->save();
        }

        $run = $this->advance($run);
        $song = Song::query()->findOrFail($this->step($run, 'song:t1')->output['song_id']);
        $instrumental = ProjectTrack::query()->findOrFail($this->step($run, 'track:t2')->output['project_track_id']);

        foreach ([$song->track, $instrumental] as $track) {
            $track->refresh();
            $track->meta = [...collect($track->meta)->all(), 'mix' => ['status' => 'ready', 'id' => 'x']];
            $track->saveQuietly();
        }

        $run = $this->advance($run);

        $this->assertSame(AgentRun::SUCCEEDED, $run->status);

        $release = Release::query()
            ->withoutGlobalScopes()
            ->findOrFail($this->step($run, 'release:al1')->output['release_id']);
        $this->assertNotNull($release->image);
        $this->assertNotNull($song->refresh()->image);

        // Plan 2 + lyrics 2 + vocal 30 + instrumental 30 + two pictures at 3.
        $this->assertSame(70, $run->credits);
    }

    public function test_artwork_that_cannot_be_drawn_is_skipped(): void
    {
        $owner = $this->ownerWith(500);
        $this->answers[] = $this->plan(['songs' => []]);

        $run = $this->advance($this->queueRun($owner, ['artwork' => true]));
        $prompts = [];

        // Each failed attempt starts another job with a gentler prompt, until the last.
        foreach (range(1, DrawArtwork::ATTEMPTS) as $attempt) {
            $cover = AiJob::query()->findOrFail($this->step($run, 'cover:al1')->output['ai_job_id']);
            $prompts[] = $cover->input['prompt'];
            $cover
                ->forceFill(['status' => AiJob::FAILED, 'failure' => 'No image came back.'])
                ->save();

            $this->assertSame($attempt, $this->step($run, 'cover:al1')->output['attempts']);

            $run = $this->advance($run);
        }

        $this->assertCount(DrawArtwork::ATTEMPTS, array_unique($prompts));
        $this->assertStringStartsWith('Abstract', $prompts[1]);
        $this->assertSame(AgentRun::RUNNING, $run->status);
        $this->assertSame(AgentStep::DONE, $this->step($run, 'cover:al1')->status);
        $this->assertNull($this->step($run, 'cover:al1')->output['file']);
    }

    public function test_albums_can_stay_studio_projects(): void
    {
        $owner = $this->ownerWith(200);
        $this->answers[] = $this->plan(['songs' => [], 'albums' => [[
            'key' => 'al1',
            'title' => 'Sketches',
            'artist' => 'a1',
            'genre' => $this->genre->title,
            'release' => false,
            'tracks' => [['title' => 'Idea One', 'style' => 'Ambient guitar', 'instrumental' => true]],
        ]]]);

        $run = $this->advance($this->queueRun($owner, ['project_privacy' => 'public_preview']));

        // The planner is told a project stays unreleased and singles are albums, not lyric sheets.
        $this->assertStringContainsString('A "project" the instructions ask for', $this->asked[0]['system']);
        $this->assertStringContainsString('put every single in "albums", never in "songs"', $this->asked[0]['system']);

        $this->assertFalse($run->plan['albums'][0]['release']);
        $this->assertFalse($run->steps()->where('kind', 'release')->exists());

        $job = AiJob::query()->findOrFail($this->step($run, 'music:t1')->output['ai_job_id']);
        $job
            ->forceFill(['status' => AiJob::SUCCEEDED, 'output' => ['stems' => [1]]])
            ->save();

        $run = $this->advance($run);
        $track = ProjectTrack::query()->findOrFail($this->step($run, 'track:t1')->output['project_track_id']);
        $track->meta = [...collect($track->meta)->all(), 'mix' => ['status' => 'ready', 'id' => 'x']];
        $track->saveQuietly();

        $run = $this->advance($run);

        $this->assertSame(AgentRun::SUCCEEDED, $run->status);
        $this->assertSame('Sketches', $track->project->title);
        $this->assertSame('public_preview', $track->project->privacy);
        $this->assertTrue($track->project->tracks_public);
        $this->assertFalse(Release::query()->withoutGlobalScopes()->where('user_id', $owner->id)->exists());
    }

    public function test_a_run_releases_an_existing_project_as_a_new_version(): void
    {
        $owner = $this->ownerWith(200);
        $artist = Artist::factory()
            ->create(['user_id' => $owner->id, 'name' => 'Ada Lumen']);
        $project = Project::factory()
            ->create(['user_id' => $owner->id, 'title' => 'Old Tapes']);
        $tracks = ProjectTrack::factory()
            ->count(2)
            ->create(['project_id' => $project->id, 'genre_id' => $this->genre->id, 'artist_id' => $artist->id]);
        $first = Release::factory()
            ->create(['user_id' => $owner->id, 'project_id' => $project->id, 'artist_id' => $artist->id, 'genre_id' => $this->genre->id]);
        $this->answers[] = json_encode([
            'artists' => [['key' => 'a1', 'name' => 'Ada Lumen', 'about' => '']],
            'albums' => [],
            'releases' => [
                ['project' => 'old tapes', 'title' => 'Old Tapes', 'version' => 'Deluxe', 'artist' => 'a1', 'genre' => $this->genre->title, 'retire' => true],
                ['project' => 'Not a project of theirs', 'title' => 'Nope', 'artist' => 'a1'],
            ],
            'songs' => [],
        ]);

        $run = $this->advance($this->queueRun($owner, ['publish' => true]));

        $this->assertStringContainsString('"Old Tapes", 2 tracks, released by Ada Lumen', $this->asked[0]['prompt']);
        $this->assertCount(1, $run->plan['releases']);
        $this->assertSame($project->id, $run->plan['releases'][0]['project_id']);
        $this->assertSame(AgentStep::WAITING, $this->step($run, "mix:r1-{$tracks[0]->id}")->status);
        Queue::assertPushed(RenderMix::class, 2);

        foreach ($tracks as $track) {
            $track->refresh();
            $track->meta = [...collect($track->meta)->all(), 'mix' => ['status' => 'ready', 'id' => 'x']];
            $track->saveQuietly();
        }

        $run = $this->advance($run);

        $this->assertSame(AgentRun::SUCCEEDED, $run->status);

        $release = Release::query()
            ->withoutGlobalScopes()
            ->findOrFail($this->step($run, 'release:r1')->output['release_id']);
        $this->assertSame('Deluxe', $release->version);
        $this->assertFalse($release->draft);
        $this->assertSame($project->id, (int) $release->project_id);
        $this->assertSame($artist->id, (int) $release->artist_id);
        $this->assertSame(
            $tracks->pluck('id')->all(),
            $release->tracks()->orderBy('project_track_id')->pluck('project_track_id')->map(fn($id) => (int) $id)->all(),
        );
        $this->assertNotNull($first->refresh()->retired_at);
        $this->assertSame(1, Artist::query()->where('user_id', $owner->id)->count());
    }

    public function test_a_run_touches_up_existing_projects_before_releasing_them(): void
    {
        Storage::fake(config('filesystems.default'));
        AiSettings::update(['ai_artwork_credits' => 3]);

        $owner = $this->ownerWith(200);
        $artist = Artist::factory()
            ->create(['user_id' => $owner->id, 'name' => 'Ada Lumen']);
        $project = Project::factory()
            ->create(['user_id' => $owner->id, 'title' => 'Old Tapes', 'image' => null]);
        $finished = Project::factory()
            ->create(['user_id' => $owner->id, 'title' => 'Finished', 'image' => 'done.jpg']);
        $tracks = ProjectTrack::factory()
            ->count(2)
            ->create(['project_id' => $project->id, 'genre_id' => $this->genre->id, 'artist_id' => $artist->id]);
        $first = Release::factory()
            ->create(['user_id' => $owner->id, 'project_id' => $project->id, 'artist_id' => $artist->id, 'genre_id' => $this->genre->id, 'image' => null]);
        $stem = ProjectTrackStem::factory()
            ->create(['track_id' => $tracks[0]->id, 'user_id' => $owner->id, 'title' => 'Vocals', 'src' => 'vocals.mp3']);
        $tracks[0]
            ->lyric()
            ->create(['lines' => [['start' => null, 'end' => null, 'text' => 'Rewind the night']], 'text' => 'Rewind the night']);

        $song = new Song;
        $song
            ->forceFill(['user_id' => $owner->id, 'title' => 'Old Tapes', 'body' => 'Rewind the night', 'project_track_id' => $tracks[0]->id])
            ->save();

        $this->answers[] = json_encode([
            'artists' => [['key' => 'a1', 'name' => 'Ada Lumen', 'about' => '']],
            'albums' => [],
            'releases' => [['project' => "#{$project->id}", 'title' => 'Old Tapes', 'artist' => 'a1']],
            'updates' => [
                ['project' => "#{$project->id}", 'artwork' => true, 'lyrics' => true, 'cover' => 'Cassette tapes on a sunny windowsill'],
                ['project' => 'Finished', 'artwork' => true, 'lyrics' => true],
                ['project' => 'Not a project of theirs', 'artwork' => true],
            ],
            'songs' => [],
        ]);

        $run = $this->queueRun($owner, ['publish' => true]);
        $run
            ->forceFill(['prompt' => "Fill the artwork and sync the lyrics of #{$project->id}, then release it"])
            ->save();
        $run = $this->advance($run);

        $this->assertStringContainsString("#{$project->id} \"Old Tapes\", 2 tracks", $this->asked[0]['prompt']);
        $this->assertStringContainsString('missing artwork, 1 track with untimed lyrics', $this->asked[0]['prompt']);
        $this->assertCount(1, $run->plan['updates']);
        $this->assertSame([['id' => $tracks[0]->id, 'title' => $tracks[0]->title]], $run->plan['updates'][0]['lyrics']);

        // The release waits for the touch-ups and takes their picture rather than drawing its own.
        $release = $this->step($run, 'release:r1');
        $this->assertContains('cover:u1', $release->depends_on);
        $this->assertContains("lyrics:u1-{$tracks[0]->id}", $release->depends_on);
        $this->assertSame('cover:u1', $release->input['artwork']);
        $this->assertFalse($run->steps()->where('key', 'cover:r1')->exists());

        $cover = AiJob::query()->findOrFail($this->step($run, 'cover:u1')->output['ai_job_id']);
        $this->assertSame('Cassette tapes on a sunny windowsill', $cover->input['prompt']);
        $sync = AiJob::query()->findOrFail($this->step($run, "lyrics:u1-{$tracks[0]->id}")->output['ai_job_id']);
        $this->assertSame('sync', $sync->input['mode']);
        $this->assertSame($stem->id, (int) $sync->input['stem_id']);

        $file = "ai/artwork/{$cover->id}/1.jpg";

        Storage::disk(config('filesystems.default'))
            ->put($file, UploadedFile::fake()->image('art.jpg', 64, 64)->getContent());
        $cover
            ->forceFill(['status' => AiJob::SUCCEEDED, 'credits' => 3, 'output' => ['images' => [['file' => $file]]]])
            ->save();
        $sync
            ->forceFill(['status' => AiJob::SUCCEEDED, 'credits' => 2, 'output' => ['lines' => 1]])
            ->save();

        foreach ($tracks as $track) {
            $track->refresh();
            $track->meta = [...collect($track->meta)->all(), 'mix' => ['status' => 'ready', 'id' => 'x']];
            $track->saveQuietly();
        }

        $run = $this->advance($run);

        $this->assertSame(AgentRun::SUCCEEDED, $run->status);
        $this->assertNotNull($project->refresh()->image);
        $this->assertNotNull($first->refresh()->image);
        $this->assertNotNull($song->refresh()->image);
        $this->assertSame('done.jpg', $finished->refresh()->image);

        $made = Release::query()
            ->withoutGlobalScopes()
            ->findOrFail($this->step($run, 'release:r1')->output['release_id']);
        $this->assertNotNull($made->image);
        $this->assertTrue($this->step($run, "lyrics:u1-{$tracks[0]->id}")->output['synced']);

        // Plan 2 + one picture at 3 + one timing at 2.
        $this->assertSame(7, $run->credits);
    }

    public function test_a_sweep_fixes_everything_missing_artwork(): void
    {
        Storage::fake(config('filesystems.default'));
        AiSettings::update(['ai_artwork_credits' => 3]);

        $owner = $this->ownerWith(200);
        $artist = Artist::factory()
            ->create(['user_id' => $owner->id, 'name' => 'Ada Lumen']);
        $bare = Project::factory()
            ->create(['user_id' => $owner->id, 'title' => 'Bare', 'image' => null]);
        $songless = Project::factory()
            ->create(['user_id' => $owner->id, 'title' => 'Songless', 'image' => 'cover.jpg']);
        $complete = Project::factory()
            ->create(['user_id' => $owner->id, 'title' => 'Complete', 'image' => 'cover.jpg']);
        $track = ProjectTrack::factory()
            ->create(['project_id' => $songless->id, 'genre_id' => $this->genre->id, 'artist_id' => $artist->id]);
        $album = Release::factory()
            ->create(['user_id' => $owner->id, 'project_id' => null, 'artist_id' => $artist->id, 'genre_id' => $this->genre->id, 'image' => null]);
        Release::factory()
            ->create(['user_id' => $owner->id, 'project_id' => null, 'artist_id' => $artist->id, 'genre_id' => $this->genre->id, 'image' => 'art.jpg']);

        $song = new Song;
        $song
            ->forceFill(['user_id' => $owner->id, 'title' => 'Songless', 'body' => 'La la', 'project_track_id' => $track->id])
            ->save();

        $this->answers[] = json_encode(['artists' => [], 'albums' => [], 'songs' => [], 'sweep' => ['artwork' => true]]);

        $run = $this->advance($this->queueRun($owner));

        $this->assertSame(
            [[$bare->id, null], [$songless->id, null], [null, $album->id]],
            array_map(fn(array $update) => [$update['project_id'], $update['release_id']], $run->plan['updates']),
        );
        $this->assertSame("fill:{$bare->id}", $this->step($run, 'cover:u1')->input['target']);
        $this->assertSame("fill-release:{$album->id}", $this->step($run, 'cover:u3')->input['target']);

        foreach (['cover:u1', 'cover:u2', 'cover:u3'] as $key) {
            $run = $this->advance($run);
            $job = AiJob::query()->findOrFail($this->step($run, $key)->output['ai_job_id']);
            $file = "ai/artwork/{$job->id}/1.jpg";

            Storage::disk(config('filesystems.default'))
                ->put($file, UploadedFile::fake()->image('art.jpg', 64, 64)->getContent());
            $job
                ->forceFill(['status' => AiJob::SUCCEEDED, 'credits' => 3, 'output' => ['images' => [['file' => $file]]]])
                ->save();
        }

        $run = $this->advance($run);

        $this->assertSame(AgentRun::SUCCEEDED, $run->status);
        $this->assertNotNull($bare->refresh()->image);
        $this->assertSame('cover.jpg', $songless->refresh()->image);
        $this->assertNotNull($song->refresh()->image);
        $this->assertNotNull($album->refresh()->image);
        $this->assertSame('cover.jpg', $complete->refresh()->image);
        $this->assertSame(2 + 3 * 3, $run->credits);
    }

    public function test_the_planner_searches_accounts_with_more_than_it_lists(): void
    {
        $owner = $this->ownerWith(200);
        $moonlight = Project::factory()
            ->create(['user_id' => $owner->id, 'title' => 'Moonlight Drive', 'image' => 'x.jpg']);
        $box = Project::factory()
            ->create(['user_id' => $owner->id, 'title' => 'Old Box', 'image' => 'x.jpg']);
        $artist = Artist::factory()
            ->create(['user_id' => $owner->id]);
        ProjectTrack::factory()
            ->create(['project_id' => $box->id, 'genre_id' => $this->genre->id, 'artist_id' => $artist->id, 'title' => 'Harbour Song']);
        Project::factory()
            ->count(AgentPlanner::PROJECT_CHOICES)
            ->create(['user_id' => $owner->id, 'image' => 'x.jpg']);

        $this->answers[] = json_encode(['search' => ['moonlight drive', 'Harbour Song']]);
        $this->answers[] = $this->plan(['artists' => [], 'albums' => []]);

        $run = $this->advance($this->queueRun($owner));

        $this->assertSame(AgentRun::SUCCEEDED, $run->status, (string) $run->error);
        $this->assertStringContainsString('search terms', $this->asked[0]['system']);
        $this->assertStringContainsString("#{$moonlight->id} \"Moonlight Drive\"", $this->asked[1]['prompt']);
        $this->assertStringContainsString("#{$box->id} \"Old Box\"", $this->asked[1]['prompt']);
    }

    public function test_the_planner_skips_search_when_everything_is_listed(): void
    {
        $owner = $this->ownerWith(200);
        $this->answers[] = $this->plan();

        $this->advance($this->queueRun($owner));

        $this->assertStringNotContainsString('search terms', $this->asked[0]['system']);
    }

    public function test_touch_ups_find_albums_and_tracks_by_title(): void
    {
        $owner = $this->ownerWith(200);
        $artist = Artist::factory()
            ->create(['user_id' => $owner->id, 'name' => 'Velora']);
        $project = Project::factory()
            ->create(['user_id' => $owner->id, 'title' => 'Night Box', 'image' => null]);
        ProjectTrack::factory()
            ->create(['project_id' => $project->id, 'genre_id' => $this->genre->id, 'artist_id' => $artist->id, 'title' => 'Fracture']);
        $album = Release::factory()
            ->has(Track::factory()->state(['genre_id' => $this->genre->id, 'audio' => null, 'title' => 'Empty Room']), 'tracks')
            ->create(['user_id' => $owner->id, 'project_id' => null, 'artist_id' => $artist->id, 'genre_id' => $this->genre->id, 'image' => null]);

        $this->answers[] = json_encode([
            'artists' => [],
            'albums' => [],
            'songs' => [],
            'updates' => [
                ['project' => 'Fracture', 'artwork' => true],
                ['project' => 'empty room', 'artwork' => true],
                ['project' => 'Not on the account', 'artwork' => true],
            ],
        ]);

        $run = $this->advance($this->queueRun($owner));

        $this->assertStringContainsString('the exact title of a project, album or track', $this->asked[0]['system']);
        $this->assertSame(
            [[$project->id, null], [null, $album->id]],
            array_map(fn(array $update) => [$update['project_id'], $update['release_id']], $run->plan['updates']),
        );
        $this->assertSame("fill:{$project->id}", $this->step($run, 'cover:u1')->input['target']);
        $this->assertSame("fill-release:{$album->id}", $this->step($run, 'cover:u2')->input['target']);
    }

    public function test_touch_ups_with_nothing_to_do_say_so(): void
    {
        $owner = $this->ownerWith(200);
        $this->answers[] = json_encode([
            'artists' => [],
            'albums' => [],
            'songs' => [],
            'updates' => [['project' => 'Fracture', 'artwork' => true], ['project' => 'Empty Room', 'artwork' => true]],
        ]);

        $run = $this->advance($this->queueRun($owner));

        $this->assertSame(AgentRun::FAILED, $run->status);
        $this->assertStringContainsString('"Fracture" and "Empty Room" weren\'t found on the account', $run->error);
    }

    public function test_a_plan_the_owner_cannot_afford_fails_before_making_anything(): void
    {
        $owner = $this->ownerWith(20);
        $this->answers[] = $this->plan();

        $run = $this->advance($this->queueRun($owner));

        $this->assertSame(AgentRun::FAILED, $run->status);
        $this->assertStringContainsString('needs about 66 credits', $run->error);
        $this->assertSame(0, $run->steps()->count());
        $this->assertFalse(Artist::query()->where('user_id', $owner->id)->exists());
    }

    public function test_a_plan_over_the_owners_song_limit_fails(): void
    {
        $owner = $this->ownerWith(200, [Feature::MAX_SONGS->value => 1]);
        $this->answers[] = $this->plan();

        $run = $this->advance($this->queueRun($owner));

        $this->assertSame(AgentRun::FAILED, $run->status);
        $this->assertStringContainsString('2 new compositions', $run->error);
    }

    public function test_the_plan_is_capped_and_reuses_the_owners_artist(): void
    {
        $owner = $this->ownerWith(500);
        $existing = Artist::factory()
            ->create(['user_id' => $owner->id, 'name' => 'Ada Lumen']);
        $tracks = array_map(fn(int $i) => ['title' => "Song {$i}", 'style' => 'Afrobeats groove', 'theme' => 'Love', 'instrumental' => true], range(1, 5));
        $this->answers[] = $this->plan([
            'albums' => [['key' => 'x', 'title' => 'Big One', 'artist' => 'Ada Lumen', 'genre' => 'Unknown genre', 'tracks' => $tracks]],
            'songs' => [],
        ]);

        $run = $this->advance($this->queueRun($owner, ['max_tracks' => 3]));

        $this->assertCount(3, $run->plan['albums'][0]['tracks']);
        $this->assertNotNull($run->plan['albums'][0]['genre_id']);
        $this->assertSame($existing->id, (int) $this->step($run, 'artist:a1')->output['artist_id']);
        $this->assertTrue($this->step($run, 'artist:a1')->output['existing']);
        $this->assertSame(1, Artist::query()->where('user_id', $owner->id)->count());
    }

    public function test_music_with_versions_takes_the_first_one(): void
    {
        $owner = $this->ownerWith(200);
        $this->answers[] = $this->plan(['songs' => []]);

        $run = $this->advance($this->queueRun($owner));

        $job = AiJob::query()->findOrFail($this->step($run, 'music:t2')->output['ai_job_id']);
        $job
            ->forceFill(['status' => AiJob::SUCCEEDED, 'output' => ['versions' => [['title' => 'A'], ['title' => 'B']]]])
            ->save();

        $this->mock(MusicVersions::class, function ($mock) {
            $mock->shouldReceive('find')->once();
            $mock->shouldReceive('url')->andReturn('https://example.test/version');
        });

        $run = $this->advance($run);

        $this->assertSame(0, $job->refresh()->output['choosing']);
        $this->assertSame(AgentStep::WAITING, $this->step($run, 'music:t2')->status, (string) $this->step($run, 'music:t2')->error);
    }

    public function test_a_failed_step_stops_the_run_and_retry_carries_on(): void
    {
        $owner = $this->ownerWith(200);
        $this->answers[] = $this->plan(['songs' => []]);

        $run = $this->advance($this->queueRun($owner));

        $job = AiJob::query()->findOrFail($this->step($run, 'music:t2')->output['ai_job_id']);
        $job
            ->forceFill(['status' => AiJob::FAILED, 'failure' => 'HTTP 500: boom'])
            ->save();

        $run = $this->advance($run);

        $this->assertSame(AgentRun::FAILED, $run->status);
        $this->assertStringContainsString('Stopped at "Harbour Breeze"', $run->error);
        $this->assertSame(AgentStep::FAILED, $this->step($run, 'music:t2')->status);
        $this->assertSame(AgentStep::SKIPPED, $this->step($run, 'release:al1')->status);

        $this->admin();

        $this->postJson("/api/admin/agent/runs/{$run->id}/retry")
            ->assertAccepted()
            ->assertJsonPath('data.status', AgentRun::RUNNING);

        $run = $this->advance($run->refresh());

        $this->assertSame(AgentStep::DONE, $this->step($run, 'artist:a1')->status);
        $this->assertNotSame($job->id, $this->step($run, 'music:t2')->output['ai_job_id']);
        $this->assertSame(1, Artist::query()->where('user_id', $owner->id)->count());
    }

    public function test_cancelling_skips_what_is_left_and_starts_the_next_run(): void
    {
        $owner = $this->ownerWith(500);
        $this->answers[] = $this->plan();

        $first = $this->advance($this->queueRun($owner));
        $second = $this->queueRun($owner);

        $this->assertSame(AgentRun::QUEUED, $second->status);

        $this->admin();

        $this->postJson("/api/admin/agent/runs/{$first->id}/cancel")
            ->assertAccepted()
            ->assertJsonPath('data.status', AgentRun::CANCELLED);

        $this->assertSame(0, $first->steps()->open()->count());
        $this->assertSame(AgentRun::PLANNING, $second->refresh()->status);

        $this->getJson("/api/admin/agent/runs/{$first->id}")
            ->assertOk()
            ->assertJsonPath('data.steps.0.key', 'artist:a1')
            ->assertJsonPath('data.counts.tracks', 2);
    }

    public function test_runs_are_announced_to_content_admins_and_the_owner(): void
    {
        $owner = $this->ownerWith(500);
        $this->answers[] = $this->plan();

        RealtimeSettings::update(['realtime_driver' => 'reverb']);
        Event::fake([RealtimeMessage::class]);

        $run = $this->advance($this->queueRun($owner));

        $sent = Event::dispatched(RealtimeMessage::class, fn(RealtimeMessage $m) => $m->event === 'agent.run')
            ->map(fn(array $event) => $event[0])
            ->values();

        $this->assertSame(
            ['private-admin.content', 'private-user.' . $owner->id],
            array_map('strval', $sent[0]->channels),
        );
        $this->assertSame(AgentRun::QUEUED, $sent[0]->data['status']);
        $this->assertSame($run->status, $sent->last()->data['status']);
        $this->assertTrue($sent->every(fn(RealtimeMessage $m) => $m->data['id'] === $run->id));
    }

    public function test_a_plan_that_is_not_json_fails_and_refunds(): void
    {
        $owner = $this->ownerWith(200);
        $this->answers[] = 'Sorry, I cannot help with that.';

        $run = $this->advance($this->queueRun($owner));

        $this->assertSame(AgentRun::FAILED, $run->status);
        $this->assertSame(200, Credits::for($owner)->total());
    }

    public function test_only_agent_accounts_can_own_runs(): void
    {
        $owner = $this->ownerWith(200);
        $owner
            ->forceFill(['content_agent' => false])
            ->save();
        $this->admin();

        $this->postJson('/api/admin/agent/runs', ['prompt' => 'Make an afrobeats album please', 'owner_id' => $owner->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('owner_id');
    }

    public function test_admins_choose_the_agent_accounts(): void
    {
        $user = User::factory()
            ->create(['username' => 'agentpick' . uniqid()]);
        $this->admin();

        $this->getJson('/api/admin/agent/owners?search=' . $user->username)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/admin/agent/owners?all=1&search=' . $user->username)
            ->assertOk()
            ->assertJsonPath('data.0.id', $user->id)
            ->assertJsonPath('data.0.contentAgent', false);

        $this->putJson("/api/admin/agent/accounts/{$user->id}", ['enabled' => true])
            ->assertAccepted()
            ->assertJsonPath('data.contentAgent', true);

        $this->assertTrue($user->refresh()->content_agent);
        $this->assertDatabaseHas('admin_actions', ['user_id' => $user->id, 'action' => 'agent.assigned']);

        $this->getJson('/api/admin/agent/owners?search=' . $user->username)
            ->assertOk()
            ->assertJsonPath('data.0.id', $user->id);
    }

    public function test_users_of_an_agent_account_run_their_own_prompts(): void
    {
        $owner = $this->ownerWith(200);
        $other = $this->ownerWith(200);
        $theirs = $this->queueRun($other);

        Sanctum::actingAs($owner);

        $this->getJson('/api/account')
            ->assertOk()
            ->assertJsonPath('data.contentAgent', true);

        $created = $this->postJson('/api/creator/agent/runs', ['prompt' => 'Make me a lofi beat tape please', 'owner_id' => $other->id])
            ->assertCreated()
            ->assertJsonPath('data.owner.id', $owner->id)
            ->assertJsonPath('data.options.projectPrivacy', 'private');

        $this->postJson('/api/creator/agent/runs', ['prompt' => 'Make me a lofi beat tape please', 'project_privacy' => 'everyone'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project_privacy');

        $this->getJson('/api/creator/agent/runs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $created->json('data.id'));

        $this->getJson("/api/creator/agent/runs/{$created->json('data.id')}")
            ->assertOk();

        $this->getJson("/api/creator/agent/runs/{$theirs->id}")
            ->assertForbidden();

        $this->postJson("/api/creator/agent/runs/{$theirs->id}/cancel")
            ->assertForbidden();

        $this->getJson('/api/admin/agent/runs')
            ->assertForbidden();
    }

    public function test_creators_on_a_plan_with_the_content_agent_use_it_in_the_studio(): void
    {
        $creator = $this->ownerWith(200, [Feature::CONTENT_AGENT->value => true]);
        $creator
            ->forceFill(['content_agent' => false])
            ->save();
        $without = $this->ownerWith(200);
        $without
            ->forceFill(['content_agent' => false])
            ->save();

        Sanctum::actingAs($creator);

        $this->getJson('/api/account')
            ->assertOk()
            ->assertJsonPath('data.contentAgent', false)
            ->assertJsonPath('data.agentAccess', true);

        $created = $this->postJson('/api/creator/agent/runs', ['prompt' => 'Make me a lofi beat tape please'])
            ->assertCreated()
            ->assertJsonPath('data.owner.id', $creator->id);

        $this->getJson("/api/creator/agent/runs/{$created->json('data.id')}")
            ->assertOk();

        Sanctum::actingAs($without);

        $this->getJson('/api/account')
            ->assertJsonPath('data.agentAccess', false);

        $this->postJson('/api/creator/agent/runs', ['prompt' => 'Make me a lofi beat tape please'])
            ->assertForbidden();
    }

    public function test_each_account_builds_one_run_at_a_time_beside_other_accounts(): void
    {
        $first = $this->ownerWith(500);
        $second = $this->ownerWith(500);

        $a = $this->queueRun($first);
        $b = $this->queueRun($first);
        $c = $this->queueRun($second);

        $this->assertSame(AgentRun::PLANNING, $a->refresh()->status);
        $this->assertSame(AgentRun::QUEUED, $b->refresh()->status);
        $this->assertSame(AgentRun::PLANNING, $c->refresh()->status);
    }

    public function test_other_users_cannot_use_the_agent(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/creator/agent/runs')
            ->assertForbidden();

        $this->postJson('/api/creator/agent/runs', ['prompt' => 'Make me a lofi beat tape please'])
            ->assertForbidden();
    }
}
