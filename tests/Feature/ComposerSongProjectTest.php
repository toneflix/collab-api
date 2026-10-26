<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Jobs\RunAiJob;
use App\Models\AiJob;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Lyric;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Release;
use App\Models\Song;
use App\Models\Track;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Credits;
use App\Services\Composer\Songs;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ComposerSongProjectTest extends TestCase
{
    use DatabaseTransactions;

    protected User $writer;

    protected Genre $genre;

    protected Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['ai.providers.replicate.key' => 'r8_test']);
        AiSettings::update(['ai_enabled' => true, 'ai_music_minimax_25_credits' => 30]);

        $this->writer = $this->writerOnPlan(true, 100);
        $this->genre = Genre::factory()
            ->create();
        $this->artist = Artist::factory()
            ->create(['user_id' => $this->writer->id]);
    }

    protected function tearDown(): void
    {
        AiSettings::flush();

        parent::tearDown();
    }

    protected function writerOnPlan(bool $aiTools, int $credits): User
    {
        $plan = Plan::create([
            'slug' => 'composer-project-' . uniqid(), 'name' => 'Composer', 'price' => 0, 'interval' => 'month',
            'features' => [Feature::AI_TOOLS->value => $aiTools, Feature::AI_CREDITS->value => $credits],
        ]);

        $user = User::factory()
            ->create();

        $user
            ->subscriptions()
            ->create([
                'plan' => $plan->slug, 'plan_id' => $plan->id, 'amount' => 0,
                'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(),
            ]);

        return $user->fresh();
    }

    protected function actingAsFresh(User $user): User
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->controller = null;
        }

        Sanctum::actingAs($user = $user->fresh());

        return $user;
    }

    protected function song(User $user, array $attributes = []): Song
    {
        return app(Songs::class)->create($user, $attributes + [
            'title' => 'Late Night Train',
            'body' => "[Verse 1]\nCity lights are humming on the late night train\nI keep my head above it but I'm soaking in the rain\n\n[Hook]\nHold on, hold on",
        ]);
    }

    protected function payload(array $extra = []): array
    {
        return $extra + ['genre_id' => $this->genre->id, 'artist_id' => $this->artist->id];
    }

    public function test_a_song_becomes_a_project_with_its_lyric_sheet(): void
    {
        $song = $this->song($this->writer);
        $this->actingAsFresh($this->writer);

        $response = $this->postJson("/api/creator/songs/{$song->id}/project", $this->payload())
            ->assertCreated()
            ->assertJsonPath('aiJob', null)
            ->assertJsonPath('data.project.title', 'Late Night Train');

        $song->refresh();
        $track = $song->track;
        $this->assertNotNull($track);
        $this->assertSame($response->json('data.project.trackId'), $track->id);
        $this->assertSame('Late Night Train', $track->title);
        $this->assertSame($this->writer->id, $track->project->user_id);

        $lyric = Lyric::query()
            ->whereMorphedTo('lyricable', $track)
            ->first();
        $this->assertSame(Lyric::WRITTEN, $lyric->source);
        $this->assertSame("City lights are humming on the late night train\nI keep my head above it but I'm soaking in the rain\nHold on, hold on", $lyric->text);

        $this->getJson("/api/creator/songs/{$song->id}")
            ->assertOk()
            ->assertJsonPath('data.project.trackId', $track->id);

        $this->postJson("/api/creator/songs/{$song->id}/project", $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('song');
    }

    public function test_the_lyric_sheet_follows_the_song_when_asked(): void
    {
        $song = $this->song($this->writer);
        $this->actingAsFresh($this->writer);

        $this->postJson("/api/creator/songs/{$song->id}/project/lyrics")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('song');

        $this->postJson("/api/creator/songs/{$song->id}/project", $this->payload())
            ->assertCreated();

        $song
            ->refresh()
            ->forceFill(['body' => "[Verse 1]\nA brand new opening line"])
            ->save();

        $this->postJson("/api/creator/songs/{$song->id}/project/lyrics")
            ->assertAccepted();

        $lyric = Lyric::query()
            ->whereMorphedTo('lyricable', $song->track)
            ->first();
        $this->assertSame('A brand new opening line', $lyric->text);
    }

    public function test_saving_the_song_keeps_times_and_reaches_released_tracks(): void
    {
        $song = $this->song($this->writer, ['body' => "[Verse 1]\nFirst line here\nSecond line here"]);
        $this->actingAsFresh($this->writer);

        $this->postJson("/api/creator/songs/{$song->id}/project", $this->payload())
            ->assertCreated();

        $song->refresh();
        $track = $song->track;

        $this->putJson("/api/creator/projects/{$track->project_id}/tracks/{$track->id}/lyrics", ['lines' => [
            ['start' => 1, 'end' => 3, 'text' => 'First line here'],
            ['start' => 3, 'end' => 5, 'text' => 'Second line here'],
        ]])
            ->assertAccepted();

        $release = Release::factory()
            ->create(['user_id' => $this->writer->id, 'draft' => false]);
        $released = Track::factory()
            ->create(['release_id' => $release->id, 'project_track_id' => $track->id, 'audio' => null]);

        $this->putJson("/api/creator/songs/{$song->id}", [
            'revision' => $song->revision,
            'body' => "[Verse 1]\nA new opening line\nFirst line here\nSecond line, changed",
        ])
            ->assertAccepted()
            ->assertJsonPath('message', 'Song saved and its lyric sheet updated.');

        $this->getJson("/api/tracks/{$released->id}/lyrics")
            ->assertOk()
            ->assertJsonPath('data.lines', [
                ['start' => null, 'end' => null, 'text' => 'A new opening line'],
                ['start' => 1, 'end' => 3, 'text' => 'First line here'],
                ['start' => null, 'end' => null, 'text' => 'Second line, changed'],
            ]);
    }

    public function test_ai_can_sing_the_song_onto_the_new_track(): void
    {
        $song = $this->song($this->writer);
        $this->actingAsFresh($this->writer);

        $this->postJson("/api/creator/songs/{$song->id}/project", $this->payload(['generate' => true]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('prompt');

        $this->postJson("/api/creator/songs/{$song->id}/project", $this->payload([
            'generate' => true,
            'prompt' => 'Moody synth pop, female vocals',
        ]))
            ->assertCreated()
            ->assertJsonPath('aiJob.kind', 'music');

        $job = AiJob::query()
            ->latest('id')
            ->first();
        $this->assertSame('minimax/music-2.5', $job->input['model']);
        $this->assertStringStartsWith("[Verse 1]\nCity lights", $job->input['lyrics']);
        $this->assertSame($song->refresh()->project_track_id, $job->project_track_id);
        $this->assertSame(70, Credits::for($this->writer)->total());
        Queue::assertPushed(RunAiJob::class);
    }

    public function test_nothing_is_made_when_the_ai_song_cannot_start(): void
    {
        $free = $this->writerOnPlan(false, 100);
        $broke = $this->writerOnPlan(true, 10);
        $freeSong = $this->song($free);
        $brokeSong = $this->song($broke);
        $before = Project::query()->count();
        $request = $this->payload(['generate' => true, 'prompt' => 'Moody synth pop, female vocals']);

        $this->actingAsFresh($free);
        $this->postJson("/api/creator/songs/{$freeSong->id}/project", $request)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('generate');

        $this->actingAsFresh($broke);
        $this->postJson("/api/creator/songs/{$brokeSong->id}/project", $request)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('generate');

        $this->assertSame($before, Project::query()->count());
        $this->assertNull($brokeSong->refresh()->project_track_id);
    }

    public function test_only_the_writer_makes_a_project_and_empty_songs_cannot(): void
    {
        $other = $this->writerOnPlan(true, 100);
        $song = $this->song($this->writer);
        $empty = $this->song($this->writer, ['body' => "[Verse 1]\n\n"]);

        $this->actingAsFresh($other);
        $this->postJson("/api/creator/songs/{$song->id}/project", $this->payload())
            ->assertNotFound();

        $this->actingAsFresh($this->writer);
        $this->postJson("/api/creator/songs/{$empty->id}/project", $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('song');
        $this->postJson("/api/creator/songs/{$song->id}/project", ['artist_id' => $this->artist->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('genre_id');
    }

    public function test_saving_a_linked_song_updates_its_lyric_sheet_and_the_track_shows_the_song(): void
    {
        $song = $this->song($this->writer, ['bpm' => 92, 'key' => 'A minor', 'settings' => ['mood' => 'hopeful']]);
        $this->actingAsFresh($this->writer);

        $this->postJson("/api/creator/songs/{$song->id}/project", $this->payload())
            ->assertCreated();

        $song->refresh();
        $track = $song->track;

        $this->putJson("/api/creator/songs/{$song->id}", [
            'revision' => $song->revision,
            'body' => "[Verse 1]\nA line written after the project",
        ])
            ->assertAccepted()
            ->assertJsonPath('message', 'Song saved and its lyric sheet updated.');

        $lyric = Lyric::query()
            ->whereMorphedTo('lyricable', $track)
            ->first();
        $this->assertSame('A line written after the project', $lyric->text);

        $this->putJson("/api/creator/songs/{$song->id}", [
            'revision' => $song->refresh()->revision,
            'title' => 'A new title',
        ])
            ->assertAccepted()
            ->assertJsonPath('message', 'Song saved.');

        $this->getJson("/api/creator/projects/{$track->project_id}/tracks/{$track->id}")
            ->assertOk()
            ->assertJsonPath('data.song.id', $song->id)
            ->assertJsonPath('data.song.body', "[Verse 1]\nA line written after the project")
            ->assertJsonPath('data.song.bpm', 92)
            ->assertJsonPath('data.song.key', 'A minor')
            ->assertJsonPath('data.song.mood', 'hopeful');
    }

    public function test_a_song_joins_a_project_the_writer_can_edit(): void
    {
        $owner = $this->writerOnPlan(true, 100);
        $project = Project::query()
            ->forceCreate(['user_id' => $owner->id, 'title' => 'Night Drive EP', 'privacy' => 'private']);
        $project
            ->collaborators()
            ->forceCreate(['user_id' => $this->writer->id, 'role' => 'writer', 'accepted' => true]);
        $project
            ->tracks()
            ->forceCreate(['title' => 'Intro', 'privacy' => 'private']);
        $song = $this->song($this->writer);
        $projects = Project::query()->count();
        $this->actingAsFresh($this->writer);

        $this->getJson('/api/creator/projects?search=night%20drive')
            ->assertOk()
            ->assertJsonPath('data.0.id', $project->id);

        $this->postJson("/api/creator/songs/{$song->id}/project", $this->payload(['project_id' => $project->id]))
            ->assertCreated()
            ->assertJsonPath('message', 'Track added to Night Drive EP with the lyric sheet.')
            ->assertJsonPath('data.project.id', $project->id);

        $track = $song
            ->refresh()
            ->track;
        $this->assertSame($project->id, $track->project_id);
        $this->assertSame('Late Night Train', $track->title);
        $this->assertSame(2, $project->tracks()->count());
        $this->assertSame($projects, Project::query()->count());

        $lyric = Lyric::query()
            ->whereMorphedTo('lyricable', $track)
            ->first();
        $this->assertStringStartsWith('City lights are humming', $lyric->text);
    }

    public function test_a_song_cannot_join_a_project_the_writer_cannot_edit(): void
    {
        $owner = $this->writerOnPlan(true, 100);
        $project = Project::query()
            ->forceCreate(['user_id' => $owner->id, 'title' => 'Not yours', 'privacy' => 'public']);
        $song = $this->song($this->writer);
        $this->actingAsFresh($this->writer);

        $this->postJson("/api/creator/songs/{$song->id}/project", $this->payload(['project_id' => $project->id]))
            ->assertForbidden();

        $this->assertSame(0, $project->tracks()->count());
        $this->assertNull($song->refresh()->project_track_id);
    }
}
