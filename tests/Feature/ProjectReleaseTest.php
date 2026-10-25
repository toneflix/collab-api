<?php

namespace Tests\Feature;

use App\Jobs\TagAudio;
use App\Jobs\TranscribeTrack;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Lyric;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use App\Models\User;
use App\Services\Lyrics\Lyrics;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Toneflix\LaravelFileable\Media;

class ProjectReleaseTest extends TestCase
{
    use DatabaseTransactions;

    protected User $owner;

    protected Project $project;

    protected ProjectTrack $track;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->owner = User::factory()
            ->create();
        $this->project = Project::factory()
            ->create(['user_id' => $this->owner->id]);
        $this->track = ProjectTrack::factory()
            ->create([
                'project_id' => $this->project->id,
                'genre_id' => Genre::factory()->create()->id,
                'artist_id' => Artist::factory()->create()->id,
            ]);
    }

    protected function releaseForm(array $extra = []): array
    {
        return [
            'title' => $this->project->title,
            'genre_id' => (string) $this->track->genre_id,
            'artist_id' => (string) $this->track->artist_id,
            'project_id' => $this->project->id,
            'tracks' => [[
                'title' => $this->track->title,
                'genre_id' => (string) $this->track->genre_id,
                'artist_id' => (string) $this->track->artist_id,
                'project_track_id' => $this->track->id,
            ]],
            ...$extra,
        ];
    }

    public function test_project_is_ready_once_a_track_has_stem_audio(): void
    {
        Sanctum::actingAs($this->owner);

        $this
            ->getJson("/api/projects/{$this->project->id}")
            ->assertJsonPath('data.ready', false);

        ProjectTrackStem::factory()
            ->create(['track_id' => $this->track->id, 'user_id' => $this->owner->id, 'src' => 'drums.mp3']);

        $this->getJson("/api/projects/{$this->project->id}")
            ->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.released', false);
    }

    public function test_owner_can_release_a_project(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson('/api/creator/releases', $this->releaseForm())
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(1, $this->project->release->tracks()->count());
        $this
            ->getJson("/api/projects/{$this->project->id}")
            ->assertJsonPath('data.draftReleaseId', $id);

        $this->putJson("/api/creator/releases/{$id}", $this->releaseForm(['draft' => false, 'publish' => true]))
            ->assertStatus(202);

        $this
            ->getJson("/api/projects/{$this->project->id}")
            ->assertJsonPath('data.released', true);
    }

    public function test_a_released_project_can_be_released_again_and_retire_the_old_version(): void
    {
        Sanctum::actingAs($this->owner);

        $first = $this
            ->postJson('/api/creator/releases', $this->releaseForm())
            ->json('data.id');
        $this
            ->putJson("/api/creator/releases/{$first}", $this->releaseForm(['draft' => false]))
            ->assertStatus(202);

        $second = $this->postJson('/api/creator/releases', $this->releaseForm(['version' => 'Radio Edit']))
            ->assertCreated()
            ->assertJsonPath('data.version', 'Radio Edit')
            ->json('data.id');

        $this->assertNotEquals($first, $second);

        $this->putJson("/api/creator/releases/{$second}", $this->releaseForm(['draft' => false, 'retire' => [$first]]))
            ->assertStatus(202);

        $this->assertSame(2, $this->project->releases()->count());
        $this->assertNotNull($this->project->releases()->find($first)->retired_at);

        $live = collect($this->getJson('/api/releases?limit=100')->json('data'))
            ->pluck('id');
        $this->assertTrue($live->contains($second));
        $this->assertFalse($live->contains($first));

        // A retired release still opens for people who have the link or bought it.
        $this
            ->getJson("/api/releases/{$first}")
            ->assertOk()
            ->assertJsonPath('data.retired', true);

        $this->putJson("/api/creator/releases/{$first}/retirement", ['retired' => false])
            ->assertStatus(202)
            ->assertJsonPath('data.retired', false);
    }

    public function test_publishing_gives_tracks_their_own_copy_of_the_mix(): void
    {
        Sanctum::actingAs($this->owner);

        $media = new Media;
        $preview = 'mix-' . uniqid() . '.mp3';
        $media->disk->put($media->mediaInfo('music_tracks', $preview)['path'], 'mix');
        $this->track
            ->forceFill(['preview' => $preview])
            ->saveQuietly();

        $id = $this
            ->postJson('/api/creator/releases', $this->releaseForm())
            ->json('data.id');
        $this
            ->putJson("/api/creator/releases/{$id}", $this->releaseForm(['draft' => false]))
            ->assertStatus(202);

        $audio = $this->project
            ->releases()
            ->find($id)
            ->tracks()
            ->value('audio');
        $path = (new Media)->mediaInfo('music_tracks', $audio)['path'];

        $this->assertNotNull($audio);
        $this->assertNotSame($preview, $audio);
        $this->assertSame('mix', $media->disk->get($path));

        $media->disk->delete([$path, $media->mediaInfo('music_tracks', $preview)['path']]);
    }

    public function test_publishing_straight_from_the_create_form_retires_earlier_versions(): void
    {
        Sanctum::actingAs($this->owner);

        $first = $this
            ->postJson('/api/creator/releases', $this->releaseForm(['draft' => false]))
            ->json('data.id');

        $this->postJson('/api/creator/releases', $this->releaseForm(['draft' => false, 'retire' => [$first]]))
            ->assertCreated();

        $this->assertNotNull($this->project->releases()->find($first)->retired_at);
    }

    public function test_released_tracks_queue_their_lyrics_and_tags_from_the_studio(): void
    {
        Sanctum::actingAs($this->owner);

        $this
            ->postJson('/api/creator/releases', $this->releaseForm(['draft' => false]))
            ->assertCreated();

        Queue::assertPushed(TranscribeTrack::class, 1);
        Queue::assertPushed(TagAudio::class, fn(TagAudio $job) => $job->inherit);
    }

    public function test_a_released_track_without_lyrics_picks_up_the_studio_sheet(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this
            ->postJson('/api/creator/releases', $this->releaseForm(['draft' => false]))
            ->json('data.id');
        $track = $this->project
            ->releases()
            ->find($id)
            ->tracks()
            ->first();

        app(Lyrics::class)
            ->save($this->track, [['start' => 1, 'end' => 2, 'text' => 'Written in the studio']], Lyric::WRITTEN, $this->owner);
        $track
            ->lyric()
            ->delete();

        $this
            ->getJson("/api/tracks/{$track->id}/lyrics")
            ->assertJsonPath('data.lines.0.text', 'Written in the studio');
        $this->assertNotNull(app(Lyrics::class)->for($track));
    }

    public function test_drafts_cannot_be_retired(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this
            ->postJson('/api/creator/releases', $this->releaseForm())
            ->json('data.id');

        $this
            ->putJson("/api/creator/releases/{$id}/retirement", ['retired' => true])
            ->assertUnprocessable();
    }

    public function test_others_cannot_release_a_project(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this
            ->postJson('/api/creator/releases', $this->releaseForm())
            ->assertForbidden();

        $this->assertNull($this->project->release()->first());
    }
}
