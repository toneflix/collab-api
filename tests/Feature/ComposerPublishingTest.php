<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Lyric;
use App\Models\Song;
use App\Models\Sound;
use App\Models\User;
use App\Services\Composer\Songs;
use App\Services\Moderation\Moderation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ComposerPublishingTest extends TestCase
{
    use DatabaseTransactions;

    protected User $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = User::factory()
            ->create();
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
            'body' => "[Verse 1]\nCity lights are humming on the late night train\nI keep my head above it but I'm soaking in the rain",
            'notes' => 'Private notes',
            'scratchpad' => 'Private ideas',
        ]);
    }

    protected function publish(Song $song, array $settings = []): Song
    {
        $this->actingAsFresh($song->user);

        $this->putJson("/api/creator/songs/{$song->id}/publishing", ['published' => true] + $settings)
            ->assertAccepted();

        return $song->refresh();
    }

    public function test_a_writer_publishes_a_song_and_anyone_can_read_it(): void
    {
        $song = $this->song($this->writer);
        $this->actingAsFresh($this->writer);

        $this->getJson("/api/songs/{$song->id}")
            ->assertNotFound();

        $this->putJson("/api/creator/songs/{$song->id}/publishing", ['published' => true, 'allow_forks' => true])
            ->assertAccepted()
            ->assertJsonPath('message', 'Composition published.')
            ->assertJsonPath('data.allowForks', true)
            ->assertJsonPath('data.allowProjects', false)
            ->assertJsonPath('data.slug', "late-night-train-{$song->id}");

        $song->refresh();
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/songs/{$song->slug}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Late Night Train')
            ->assertJsonPath('data.writer.username', $this->writer->username)
            ->assertJsonPath('data.allowForks', true)
            ->assertJsonPath('data.isOwner', false)
            ->assertJsonMissingPath('data.notes')
            ->assertJsonMissingPath('data.scratchpad');

        $this->getJson('/api/songs?search=humming')
            ->assertOk()
            ->assertJsonPath('data.0.slug', $song->slug)
            ->assertJsonPath('data.0.excerpt.0', 'City lights are humming on the late night train')
            ->assertJsonMissingPath('data.0.body')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_the_address_stays_and_unpublishing_hides_the_song(): void
    {
        $song = $this->publish($this->song($this->writer));
        $slug = $song->slug;

        $this->putJson("/api/creator/songs/{$song->id}", ['title' => 'Renamed', 'revision' => $song->revision])
            ->assertAccepted();
        $this->putJson("/api/creator/songs/{$song->id}/publishing", ['published' => false])
            ->assertAccepted()
            ->assertJsonPath('message', 'Composition unpublished.');
        $this->putJson("/api/creator/songs/{$song->id}/publishing", ['published' => true])
            ->assertAccepted()
            ->assertJsonPath('data.slug', $slug);
        $this->putJson("/api/creator/songs/{$song->id}/publishing", ['published' => false])
            ->assertAccepted();

        $this->actingAsFresh(User::factory()->create());

        $this->getJson("/api/songs/{$slug}")
            ->assertNotFound();
        $this->getJson('/api/songs')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_a_song_without_lyrics_cannot_be_published(): void
    {
        $song = $this->song($this->writer, ['body' => "[Verse]\n"]);
        $this->actingAsFresh($this->writer);

        $this->putJson("/api/creator/songs/{$song->id}/publishing", ['published' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('published');
    }

    public function test_others_fork_only_when_the_writer_allows_it(): void
    {
        $beat = Sound::query()->forceCreate([
            'user_id' => null,
            'source' => 'freesound',
            'name' => 'Library beat',
            'file' => 'x.mp3',
        ]);
        $song = $this->song($this->writer, ['bpm' => 92, 'beat_sound_id' => $beat->id]);
        $this->publish($song);

        $fan = $this->actingAsFresh(User::factory()->create());

        $this->postJson("/api/creator/songs/{$song->id}/forks")
            ->assertForbidden();

        $this->publish($song, ['allow_forks' => true]);
        $this->actingAsFresh($fan);

        $response = $this->postJson("/api/creator/songs/{$song->id}/forks")
            ->assertCreated()
            ->assertJsonPath('data.title', 'Late Night Train')
            ->assertJsonPath('data.bpm', 92)
            ->assertJsonPath('data.publishedAt', null)
            ->assertJsonPath('data.forkedFrom.slug', $song->slug)
            ->assertJsonPath('data.notes', '');

        $fork = Song::find($response->json('data.id'));
        $this->assertSame($fan->id, $fork->user_id);
        $this->assertSame($song->id, $fork->forked_from_id);
        $this->assertSame($beat->id, $fork->beat_sound_id);
        $this->assertSame($song->body, $fork->body);

        $this->getJson("/api/songs/{$song->slug}")
            ->assertJsonPath('data.forksCount', 1);
    }

    public function test_unpublished_songs_cannot_be_forked_by_others(): void
    {
        $song = $this->song($this->writer);
        $song
            ->forceFill(['allow_forks' => true])
            ->save();

        $this->actingAsFresh(User::factory()->create());

        $this->postJson("/api/creator/songs/{$song->id}/forks")
            ->assertNotFound();
    }

    public function test_others_start_a_project_with_a_song_when_allowed(): void
    {
        $song = $this->song($this->writer);
        $this->publish($song, ['allow_projects' => true]);

        $fan = User::factory()
            ->create();
        $genre = Genre::factory()
            ->create();
        $artist = Artist::factory()
            ->create(['user_id' => $fan->id]);
        $this->actingAsFresh($fan);

        $response = $this->postJson("/api/creator/songs/{$song->id}/project", [
            'genre_id' => $genre->id,
            'artist_id' => $artist->id,
        ])
            ->assertCreated()
            ->assertJsonPath('project.title', 'Late Night Train')
            ->assertJsonMissingPath('data.notes');

        $song->refresh();
        $this->assertNull($song->project_track_id);

        $project = $fan
            ->projects()
            ->findOrFail($response->json('project.id'));
        $this->assertStringContainsString('Late Night Train', (string) $project->description);

        $track = $project
            ->tracks()
            ->firstOrFail();
        $lyric = Lyric::query()
            ->whereMorphedTo('lyricable', $track)
            ->first();
        $this->assertStringStartsWith('City lights are humming', $lyric->text);

        $this->publish($song, ['allow_projects' => false]);
        $this->actingAsFresh($fan);

        $this->postJson("/api/creator/songs/{$song->id}/project", [
            'genre_id' => $genre->id,
            'artist_id' => $artist->id,
        ])
            ->assertForbidden();
    }

    public function test_a_writer_sets_and_removes_artwork(): void
    {
        Storage::fake(config('filesystems.default'));

        $song = $this->song($this->writer);
        $this->actingAsFresh($this->writer);

        $this->postJson("/api/creator/songs/{$song->id}/artwork", [
            'image' => UploadedFile::fake()->image('cover.jpg', 800, 800),
        ])
            ->assertAccepted()
            ->assertJsonPath('data.hasArtwork', true);

        $this->assertNotNull($song->refresh()->image);

        $this->deleteJson("/api/creator/songs/{$song->id}/artwork")
            ->assertAccepted()
            ->assertJsonPath('data.hasArtwork', false);

        $this->actingAsFresh(User::factory()->create());

        $this->postJson("/api/creator/songs/{$song->id}/artwork", [
            'image' => UploadedFile::fake()->image('cover.jpg'),
        ])
            ->assertNotFound();
    }

    public function test_explore_lists_new_and_most_forked_songs(): void
    {
        $quiet = $this->publish($this->song($this->writer, ['title' => 'Quiet one']));
        $loved = $this->publish($this->song($this->writer, ['title' => 'Loved one']), ['allow_forks' => true]);
        $this->song($this->writer, ['title' => 'Draft']);

        $this->actingAsFresh(User::factory()->create());
        $this->postJson("/api/creator/songs/{$loved->id}/forks")
            ->assertCreated();

        $this->getJson('/api/explore?new_songs=10&top_songs=10')
            ->assertOk()
            ->assertJsonPath('data.newSongs.meta.total', 2)
            ->assertJsonPath('data.topSongs.list.0.slug', $loved->slug)
            ->assertJsonPath('data.topSongs.list.1.slug', $quiet->slug);
    }

    public function test_a_taken_down_song_is_hidden_and_cannot_be_published(): void
    {
        $song = $this->publish($this->song($this->writer));
        $admin = User::factory()
            ->create();

        app(Moderation::class)->takeDown($song, $admin, 'copyright');

        $this->actingAsFresh(User::factory()->create());
        $this->getJson("/api/songs/{$song->slug}")
            ->assertNotFound();

        $this->actingAsFresh($this->writer);
        $this->putJson("/api/creator/songs/{$song->id}/publishing", ['published' => true])
            ->assertUnprocessable();
    }
}
