<?php

namespace Tests\Feature;

use App\Enums\TrackOrigin;
use App\Models\AiReview;
use App\Models\Artist;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Moderation\Ai\ModeratorModel;
use App\Services\Releases\TrackOrigins;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TrackOriginTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected User $owner;

    protected User $collaborator;

    protected Release $release;

    /** @var list<string> */
    protected array $answers = [];

    /** @var list<string> */
    protected array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->admin = User::factory()
            ->create();
        $this->admin->givePermissionTo(Permission::findOrCreate('manage-content'));
        $this->owner = User::factory()
            ->create();
        $this->collaborator = User::factory()
            ->create();

        $artist = Artist::factory()
            ->create(['user_id' => $this->owner->id]);

        $this->release = Release::factory()
            ->create(['user_id' => $this->owner->id, 'artist_id' => $artist->id]);

        $test = $this;
        $this->app->instance(ModeratorModel::class, new class($test) implements ModeratorModel
        {
            public function __construct(protected TrackOriginTest $test) {}

            public function ask(string $system, string $prompt, ?string $image = null): string
            {
                return $this->test->answer($prompt);
            }

            public function name(): string
            {
                return 'fake/moderator';
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

        parent::tearDown();
    }

    public function answer(string $prompt): string
    {
        $this->asked[] = $prompt;

        return array_shift($this->answers) ?? '';
    }

    protected function verdict(string $decision): string
    {
        return json_encode(['decision' => $decision, 'confidence' => 90, 'summary' => 'Judged.', 'note' => 'Ranked.']);
    }

    /**
     * A studio track with the given stems, each [user, generated].
     *
     * @param  list<array{0: User, 1: bool}>  $stems
     */
    protected function studioTrack(array $stems): ProjectTrack
    {
        $project = Project::factory()
            ->create(['user_id' => $this->owner->id]);
        $track = ProjectTrack::factory()
            ->create(['project_id' => $project->id]);

        foreach ($stems as [$user, $generated]) {
            $meta = $generated ? ['ai' => ['prompt' => 'warm pads']] : ['name' => 'Take'];

            ProjectTrackStem::query()->create([
                'user_id' => $user->id,
                'track_id' => $track->id,
                'title' => 'Stem',
                'meta' => $meta,
            ]);
        }

        return $track;
    }

    protected function release(?ProjectTrack $from = null): Track
    {
        return Track::factory()
            ->create(['release_id' => $this->release->id, 'project_track_id' => $from?->id, 'audio' => null])
            ->refresh();
    }

    public function test_studio_tracks_are_ranked_from_their_stems(): void
    {
        $recorded = $this->release($this->studioTrack([[$this->owner, false], [$this->owner, false]]));
        $collab = $this->release($this->studioTrack([[$this->owner, false], [$this->collaborator, false]]));
        $agentic = $this->release($this->studioTrack([[$this->owner, true], [$this->collaborator, false]]));

        $this->assertSame(TrackOrigin::RECORDED, $recorded->origin);
        $this->assertSame(TrackOrigin::COLLABORATION, $collab->origin);
        $this->assertSame(TrackOrigin::AGENTIC, $agentic->origin);
        $this->assertSame(TrackOrigins::SYSTEM, $collab->origin_by);
    }

    public function test_direct_uploads_wait_for_the_ai_moderator(): void
    {
        $upload = $this->release();

        $this->assertNull($upload->origin);
        $this->assertCount(0, $this->asked);

        $this->answers = [$this->verdict('approve')];

        Sanctum::actingAs($this->admin);
        $this
            ->postJson("/api/admin/moderation/tracks/{$upload->id}/ai-review")
            ->assertAccepted();

        $upload->refresh();
        $this->assertSame(TrackOrigin::RECORDED, $upload->origin);
        $this->assertSame(TrackOrigins::AI_MODERATOR, $upload->origin_by);
        $this->assertStringContainsString($upload->title, $this->asked[0]);
        $this->assertSame(AiReview::DECIDED, $upload->aiReviews()->sole()->status);

        $other = $this->release();
        $this->answers = [$this->verdict('reject')];
        $this
            ->postJson("/api/admin/moderation/tracks/{$other->id}/ai-review")
            ->assertAccepted();

        $this->assertSame(TrackOrigin::AGENTIC, $other->refresh()->origin);
    }

    public function test_direct_uploads_are_judged_automatically_when_turned_on(): void
    {
        AiSettings::update(['ai_moderator_origins_auto' => '1']);
        $this->answers = [$this->verdict('approve')];

        $upload = $this->release();

        $this->assertSame(TrackOrigin::RECORDED, $upload->refresh()->origin);
    }

    public function test_admin_rank_stands_until_set_back_to_automatic(): void
    {
        $track = $this->release($this->studioTrack([[$this->owner, false]]));
        $form = ['title' => $track->title, 'genre_id' => $track->genre_id];

        Sanctum::actingAs($this->admin);
        $this
            ->putJson("/api/admin/content/track/{$track->id}", [...$form, 'origin' => 'agentic'])
            ->assertStatus(202)
            ->assertJsonPath('data.fields.origin', 'agentic')
            ->assertJsonPath('data.fields.originBy', TrackOrigins::ADMIN);

        $this->artisan('tracks:rank --all')->assertSuccessful();
        $this->assertSame(TrackOrigin::AGENTIC, $track->refresh()->origin);

        $this->getJson('/api/admin/content/track?status=agentic')
            ->assertJsonFragment(['id' => $track->id]);

        $this
            ->putJson("/api/admin/content/track/{$track->id}", [...$form, 'origin' => ''])
            ->assertStatus(202)
            ->assertJsonPath('data.fields.origin', 'recorded')
            ->assertJsonPath('data.fields.originBy', TrackOrigins::SYSTEM);
    }

    public function test_rank_command_fills_unranked_tracks(): void
    {
        $track = $this->release($this->studioTrack([[$this->collaborator, false]]));
        $track->forceFill(['origin' => null, 'origin_by' => null])->saveQuietly();

        $this->artisan('tracks:rank')->assertSuccessful();

        $this->assertSame(TrackOrigin::COLLABORATION, $track->refresh()->origin);
    }
}
