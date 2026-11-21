<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Models\MusicVideo;
use App\Models\MusicVideoCharacter;
use App\Models\MusicVideoShot;
use App\Models\MusicVideoTake;
use App\Models\Plan;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Credits;
use App\Services\Ai\Video\ShotList;
use App\Services\Composer\Ai\LyricModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Toneflix\LaravelFileable\Media;

class AiMusicVideoTest extends TestCase
{
    use DatabaseTransactions;

    protected const REPLICATE = 'https://replicate.test/v1';

    protected const CDN = 'https://replicate.delivery/video';

    protected User $owner;

    protected Track $track;

    /** @var list<string> */
    protected array $temp = [];

    protected bool $clipsFail = false;

    /** Stills whose prompt has this text get a 502 from Replicate */
    protected ?string $stillsDown = null;

    /** Replicate answers 502 to this many still requests first */
    protected int $stillOutages = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        config([
            'ai.providers.replicate.key' => 'r8_test',
            'ai.providers.replicate.url' => self::REPLICATE,
        ]);

        AiSettings::update(['ai_enabled' => true, 'ai_video_storyboard_credits' => 20, 'ai_video_location_storyboard_credits' => 6, 'ai_video_credits' => 190]);

        $this->owner = $this->subscriber([Feature::AI_TOOLS->value => true, Feature::AI_CREDITS->value => 1000]);
        $this->track = $this->track($this->owner);

        $this->app->instance(LyricModel::class, new class implements LyricModel
        {
            public function ask(string $system, string $prompt, int $maxTokens = 1200): string
            {
                if (str_contains($system, '"locations"')) {
                    return json_encode([
                        'style' => ['look' => 'Neon night', 'character' => 'A young woman with short braids'],
                        'characters' => [
                            ['key' => 'singer', 'name' => 'The singer', 'description' => 'A young woman with short braids in a red jacket'],
                            ['key' => 'dancer', 'name' => 'The dancer', 'description' => 'A tall man in a silver coat'],
                            ['key' => 'driver', 'name' => 'The driver', 'description' => 'An older man in a grey cab driver jacket'],
                        ],
                        'locations' => [
                            ['key' => 'street', 'name' => 'Neon street', 'setting' => 'A wet street at night', 'frames' => [
                                ['key' => 'street_wide', 'kind' => 'broll', 'prompt' => 'A wide neon street in the rain', 'characters' => []],
                                ['key' => 'street_sing', 'kind' => 'performance', 'prompt' => 'The singer under a street light'],
                                ['key' => 'street_detail', 'kind' => 'broll', 'prompt' => 'The dancer spinning by a shop window', 'characters' => ['dancer']],
                                ['key' => 'street_extra', 'kind' => 'broll', 'prompt' => 'A fourth frame that is dropped'],
                            ]],
                            ['key' => 'roof', 'name' => 'Rooftop', 'setting' => 'A rooftop at dawn', 'frames' => [
                                ['key' => 'roof_sing', 'kind' => 'performance', 'prompt' => 'The singer on a rooftop at dawn'],
                            ]],
                        ],
                        'shots' => [
                            ['start' => 0, 'frame' => 'street_wide', 'action' => 'Slow push in.'],
                            ['start' => 2.5, 'frame' => 'street_sing', 'action' => 'She sings to the lens.'],
                            ['start' => 5, 'frame' => 'street_sing', 'action' => 'Handheld drift.'],
                            ['start' => 6.2, 'frame' => 'nowhere', 'action' => 'Dropped.'],
                            ['start' => 7.5, 'frame' => 'street_wide', 'action' => 'Crane up.'],
                            ['start' => 10, 'frame' => 'roof_sing', 'action' => 'Sunrise behind her.'],
                        ],
                    ]);
                }

                return json_encode([
                    'style' => ['look' => 'Neon night', 'character' => 'A young woman with short braids'],
                    'characters' => [
                        ['key' => 'driver', 'name' => 'The driver', 'description' => 'An older man in a grey cab driver jacket'],
                        ['key' => 'singer', 'name' => 'The singer', 'description' => 'A young woman with short braids in a red jacket'],
                    ],
                    'shots' => [
                        ['start' => 0, 'kind' => 'broll', 'prompt' => 'A city skyline at dusk', 'characters' => []],
                        ['start' => 3.1, 'kind' => 'performance', 'prompt' => 'The singer on a rooftop'],
                        ['start' => 6.2, 'kind' => 'broll', 'prompt' => 'Cars streaking past, the driver at the wheel', 'characters' => ['driver', 'nobody']],
                        ['start' => 8.9, 'kind' => 'performance', 'prompt' => 'The singer under a street light'],
                    ],
                ]);
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

        $this->fakeReplicate();
    }

    protected function tearDown(): void
    {
        array_map(fn(string $file) => @unlink($file), $this->temp);
        AiSettings::flush();

        parent::tearDown();
    }

    public function test_a_storyboard_is_planned_on_the_beat_with_a_still_per_shot(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'brief' => 'Night city', 'ratio' => '16:9'])
            ->assertCreated()
            ->assertJsonPath('data.status', MusicVideo::STORYBOARD)
            ->assertJsonPath('data.minutes', 1)
            ->assertJsonCount(4, 'data.shots')
            ->json('data.id');

        $shots = MusicVideoShot::query()
            ->where('music_video_id', $id)
            ->orderBy('position')
            ->get();

        $this->assertSame([0.0, 3.0, 6.0, 9.0], $shots->pluck('start')->all());
        $this->assertSame(12.0, $shots->last()->end);
        $this->assertSame([MusicVideoShot::BROLL, MusicVideoShot::PERFORMANCE], $shots->take(2)->pluck('kind')->all());
        $this->assertTrue($shots->every(fn(MusicVideoShot $shot) => (bool) $shot->keyframe));
        $this->assertSame(980, Credits::for($this->owner)->total());

        $this->assertSame([[], ['singer'], ['driver'], ['singer']], $shots->pluck('characters')->all());

        $characters = MusicVideoCharacter::query()
            ->where('music_video_id', $id)
            ->orderByDesc('singer')
            ->orderBy('id')
            ->get();

        $this->assertSame(['singer', 'driver'], $characters->pluck('key')->all());
        $this->assertTrue($characters->every(fn(MusicVideoCharacter $character) => (bool) $character->sheet));

        $this->getJson("/api/creator/videos/{$id}")
            ->assertJsonPath('data.characters.0.key', 'singer')
            ->assertJsonPath('data.characters.1.name', 'The driver')
            ->assertJsonPath('data.shots.2.characters', ['driver']);

        Http::assertSent(fn(Request $request) => str_ends_with($request->url(), '/seedream-4/predictions')
            && str_contains($request['input']['prompt'], 'Character reference sheet')
            && str_contains($request['input']['prompt'], 'grey cab driver jacket'));
        Http::assertSent(fn(Request $request) => str_ends_with($request->url(), '/seedream-4/predictions')
            && $request['input']['aspect_ratio'] === '16:9'
            && str_contains($request['input']['prompt'], 'Cars streaking')
            && count($request['input']['image_input'] ?? []) === 1
            && str_contains($request['input']['prompt'], 'The driver is the person on reference sheet 1'));
        Http::assertNotSent(fn(Request $request) => str_ends_with($request->url(), '/seedream-4/predictions')
            && str_contains($request['input']['prompt'], 'city skyline')
            && isset($request['input']['image_input']));
    }

    public function test_a_location_storyboard_draws_each_frame_once(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos")
            ->assertCreated()
            ->assertJsonPath('data.mode', MusicVideo::LOCATIONS)
            ->assertJsonPath('data.status', MusicVideo::STORYBOARD)
            ->assertJsonPath('data.locations.0.name', 'Neon street')
            ->assertJsonPath('data.locations.1.key', 'roof')
            ->assertJsonPath('data.shots.0.location', 'street')
            ->assertJsonCount(5, 'data.shots')
            ->json('data.id');

        $shots = MusicVideoShot::query()
            ->where('music_video_id', $id)
            ->orderBy('position')
            ->get();

        $this->assertSame(['street_wide', 'street_sing', 'street_detail', 'street_wide', 'roof_sing'], $shots->pluck('frame')->all());
        $this->assertSame([MusicVideoShot::BROLL, MusicVideoShot::PERFORMANCE, MusicVideoShot::BROLL, MusicVideoShot::BROLL, MusicVideoShot::PERFORMANCE], $shots->pluck('kind')->all());
        $this->assertSame('A wide neon street in the rain Crane up.', $shots[3]->prompt);
        $this->assertSame($shots[0]->keyframe, $shots[3]->keyframe);
        $this->assertSame(4, $shots->pluck('keyframe')->unique()->count());
        $this->assertSame([[], ['singer'], ['dancer'], [], ['singer']], $shots->pluck('characters')->all());
        $this->assertSame(994, Credits::for($this->owner)->total());

        $characters = MusicVideoCharacter::query()
            ->where('music_video_id', $id)
            ->pluck('key')
            ->all();

        $this->assertEqualsCanonicalizing(['singer', 'dancer'], $characters);

        $stills = collect(Http::recorded())
            ->filter(fn(array $sent) => str_ends_with($sent[0]->url(), '/seedream-4/predictions'));

        $this->assertCount(6, $stills);
        $this->assertSame(1, $stills->filter(fn(array $sent) => str_contains($sent[0]['input']['prompt'], 'wide neon street'))->count());
    }

    public function test_a_failed_location_storyboard_retries_only_the_missing_frames(): void
    {
        Sleep::fake();
        Sanctum::actingAs($this->owner);
        $this->stillsDown = 'wide neon street';

        $id = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos")
            ->assertJsonPath('data.status', MusicVideo::FAILED)
            ->json('data.id');

        $this->assertSame(1000, Credits::for($this->owner)->total());

        $this->stillsDown = null;

        $this->postJson("/api/creator/videos/{$id}/storyboard")
            ->assertAccepted()
            ->assertJsonPath('data.status', MusicVideo::STORYBOARD);

        $shots = MusicVideoShot::query()
            ->where('music_video_id', $id)
            ->orderBy('position')
            ->get();

        $this->assertTrue($shots->every(fn(MusicVideoShot $shot) => (bool) $shot->keyframe));
        $this->assertSame($shots[0]->keyframe, $shots[3]->keyframe);
        $this->assertSame(998, Credits::for($this->owner)->total());
    }

    public function test_a_brief_replicate_outage_is_waited_out(): void
    {
        Sleep::fake();
        Sanctum::actingAs($this->owner);
        $this->stillOutages = 2;

        $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->assertCreated()
            ->assertJsonPath('data.status', MusicVideo::STORYBOARD);
    }

    public function test_a_failed_storyboard_keeps_its_stills_and_retries_only_the_missing_ones(): void
    {
        Sleep::fake();
        Sanctum::actingAs($this->owner);
        $this->stillsDown = 'Cars streaking';

        $id = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->assertJsonPath('data.status', MusicVideo::FAILED)
            ->json('data.id');

        $shots = MusicVideoShot::query()
            ->where('music_video_id', $id)
            ->orderBy('position')
            ->get();
        $kept = $shots
            ->whereNotNull('keyframe')
            ->pluck('keyframe', 'id');

        $this->assertSame([1, 2, 4], $shots->whereNotNull('keyframe')->pluck('position')->values()->all());
        $this->assertSame(1000, Credits::for($this->owner)->total());

        $this->stillsDown = null;

        $this->postJson("/api/creator/videos/{$id}/storyboard")
            ->assertAccepted()
            ->assertJsonPath('data.status', MusicVideo::STORYBOARD)
            ->assertJsonCount(4, 'data.shots');

        $shots = MusicVideoShot::query()
            ->where('music_video_id', $id)
            ->get()
            ->keyBy('id');

        $this->assertTrue($shots->every(fn(MusicVideoShot $shot) => (bool) $shot->keyframe));
        $this->assertEquals($kept->all(), $shots->only($kept->keys()->all())->pluck('keyframe', 'id')->all());
        $this->assertSame(995, Credits::for($this->owner)->total());

        $this->postJson("/api/creator/videos/{$id}/storyboard")
            ->assertUnprocessable();
    }

    public function test_retrying_a_storyboard_planned_without_characters_draws_the_singer_sheet(): void
    {
        Sleep::fake();
        Sanctum::actingAs($this->owner);
        $this->stillsDown = 'Cars streaking';

        $id = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->json('data.id');

        MusicVideoCharacter::query()
            ->where('music_video_id', $id)
            ->delete();
        MusicVideoShot::query()
            ->where('music_video_id', $id)
            ->update(['characters' => null]);

        $this->stillsDown = null;

        $this->postJson("/api/creator/videos/{$id}/storyboard")
            ->assertJsonPath('data.status', MusicVideo::STORYBOARD)
            ->assertJsonPath('data.characters.0.key', 'singer')
            ->assertJsonPath('data.characters.0.description', 'A young woman with short braids')
            ->assertJsonPath('data.shots.1.characters', ['singer'])
            ->assertJsonPath('data.shots.2.characters', []);
    }

    public function test_creators_can_bring_their_own_character_sheets(): void
    {
        Sanctum::actingAs($this->owner);

        $sheets = fn() => [
            ['name' => 'Ada', 'singer' => true, 'sheet' => UploadedFile::fake()->image('ada.jpg', 1280, 720)],
            ['name' => 'The driver', 'description' => 'Grey beard, flat cap', 'sheet' => UploadedFile::fake()->image('driver.jpg', 1280, 720)],
        ];

        $this->post("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'characters' => $sheets(), 'consent' => '1'], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('characters');

        $artist = $this->subscriber([
            Feature::AI_TOOLS->value => true,
            Feature::AI_CREDITS->value => 1000,
            Feature::VIDEO_OWN_PHOTOS->value => true,
        ]);
        $track = $this->track($artist);

        Sanctum::actingAs($artist);

        $this->post("/api/creator/tracks/{$track->slug}/videos", ['mode' => 'legacy', 'characters' => $sheets()], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('consent');

        $this->post("/api/creator/tracks/{$track->slug}/videos", [
            'characters' => [['name' => 'Ada', 'sheet' => UploadedFile::fake()->image('ada.jpg', 1280, 720)]],
            'consent' => '1',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('characters');

        $id = $this->post("/api/creator/tracks/{$track->slug}/videos", ['mode' => 'legacy', 'characters' => $sheets(), 'consent' => '1'], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', MusicVideo::STORYBOARD)
            ->assertJsonPath('data.characters.0.key', 'singer')
            ->assertJsonPath('data.characters.0.name', 'Ada')
            ->assertJsonPath('data.characters.0.provided', true)
            ->assertJsonPath('data.characters.1.key', 'the_driver')
            ->assertJsonCount(2, 'data.characters')
            ->json('data.id');

        $this->assertSame(['singer', 'the_driver'], MusicVideoCharacter::query()
            ->where('music_video_id', $id)
            ->orderByDesc('singer')
            ->pluck('key')
            ->all());

        Http::assertNotSent(fn(Request $request) => str_ends_with($request->url(), '/seedream-4/predictions')
            && str_contains($request['input']['prompt'], 'Character reference sheet'));
        Http::assertSent(fn(Request $request) => str_ends_with($request->url(), '/seedream-4/predictions')
            && str_contains($request['input']['prompt'], 'The singer on a rooftop')
            && str_contains($request['input']['prompt'], 'Ada is the person on reference sheet 1'));
    }

    public function test_rendering_lip_syncs_singing_shots_and_cuts_the_video_over_the_song(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->json('data.id');

        $this->postJson("/api/creator/videos/{$id}/render")
            ->assertAccepted()
            ->assertJsonPath('data.status', MusicVideo::READY)
            ->assertJsonPath('data.billing', MusicVideo::CREDITS);

        $video = MusicVideo::query()
            ->findOrFail($id);

        $this->assertNotNull($video->file);
        $this->assertSame(790, Credits::for($this->owner)->total());

        Http::assertSent(fn(Request $request) => str_ends_with($request->url(), '/p-video-2-pro-avatar/predictions')
            && isset($request['input']['audio']));
        Http::assertSent(fn(Request $request) => str_ends_with($request->url(), '/prunaai/p-video/predictions')
            && $request['input']['duration'] === 3);

        $info = $this->probe($video->file);

        $this->assertStringContainsString('h264,1280,720', $info);
        $this->assertStringContainsString('aac', $info);
        $this->assertEqualsWithDelta(12.0, $this->duration($video->file), 0.2);
    }

    public function test_finished_videos_can_be_stitched_again_for_free(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->json('data.id');

        $this->postJson("/api/creator/videos/{$id}/render")
            ->assertAccepted();

        $video = MusicVideo::query()
            ->findOrFail($id);

        $file = $video->file;
        $credits = Credits::for($this->owner)->total();
        $requests = count(Http::recorded());

        $this->artisan('videos:restitch', ['videos' => [$id]])
            ->assertSuccessful();

        $video->refresh();

        $this->assertSame(MusicVideo::READY, $video->status);
        $this->assertNotSame($file, $video->file);
        $this->assertFalse(Storage::disk(config('filesystems.default'))->exists($file));
        $this->assertSame($credits, Credits::for($this->owner)->total());
        $this->assertCount($requests, Http::recorded());
    }

    public function test_plan_video_minutes_pay_for_the_render_before_credits(): void
    {
        $owner = $this->subscriber([Feature::AI_TOOLS->value => true, Feature::AI_CREDITS->value => 1000, Feature::VIDEO_MINUTES->value => 2]);
        $track = $this->track($owner);
        Sanctum::actingAs($owner);

        $id = $this->postJson("/api/creator/tracks/{$track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->json('data.id');

        $this->postJson("/api/creator/videos/{$id}/render")
            ->assertAccepted()
            ->assertJsonPath('data.billing', MusicVideo::MINUTES);

        $this->assertSame(980, Credits::for($owner)->total());
        $this->getJson("/api/creator/videos/{$id}")
            ->assertOk()
            ->assertJsonPath('minutesLeft', 1);
        $this->getJson('/api/creator/videos')
            ->assertOk()
            ->assertJsonPath('minutesLeft', 1)
            ->assertJsonPath('ownPhotos', false);
    }

    public function test_a_failed_render_goes_back_to_the_storyboard_and_refunds(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->json('data.id');

        $this->clipsFail = true;

        $this->postJson("/api/creator/videos/{$id}/render")
            ->assertAccepted();

        $video = MusicVideo::query()
            ->findOrFail($id);

        $this->assertSame(MusicVideo::STORYBOARD, $video->status);
        $this->assertNull($video->billing);
        $this->assertNotNull($video->error);
        $this->assertSame(980, Credits::for($this->owner)->total());
    }

    public function test_a_render_pays_only_for_the_clips_it_still_has_to_make(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->json('data.id');

        $this->postJson("/api/creator/videos/{$id}/render")
            ->assertJsonPath('data.status', MusicVideo::READY);

        $this->assertSame(790, Credits::for($this->owner)->total());

        MusicVideoShot::query()
            ->where('music_video_id', $id)
            ->where('position', 2)
            ->update(['take_id' => null]);

        $this->postJson("/api/creator/videos/{$id}/render")
            ->assertJsonPath('data.status', MusicVideo::READY);

        // The 3 second shot at 190 credits a minute
        $this->assertSame(790 - 10, Credits::for($this->owner)->total());
        $this->assertSame(5, MusicVideoTake::query()->where('music_video_id', $id)->count());
    }

    public function test_a_ready_video_is_stitched_again_for_free_after_b_roll_moves(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->json('data.id');
        $ids = array_column($this->postJson("/api/creator/videos/{$id}/render")->json('data.shots'), 'id');
        $file = MusicVideo::query()
            ->findOrFail($id)
            ->file;

        $this->putJson("/api/creator/videos/{$id}/shots", ['shots' => $this->slots([$ids[2], $ids[1], $ids[0], $ids[3]])])
            ->assertAccepted()
            ->assertJsonPath('data.status', MusicVideo::READY)
            ->assertJsonPath('data.edited', true)
            ->assertJsonPath('data.shots.0.rendered', true)
            ->assertJsonPath('data.shots.2.rendered', true);

        $this->postJson("/api/creator/videos/{$id}/render")
            ->assertAccepted()
            ->assertJsonPath('data.status', MusicVideo::READY)
            ->assertJsonPath('data.edited', false);

        $video = MusicVideo::query()
            ->findOrFail($id);

        $this->assertSame(790, Credits::for($this->owner)->total());
        $this->assertNotSame($file, $video->file);
        $this->assertFalse(Storage::disk(config('filesystems.default'))->exists($file));
        $this->assertSame([$ids[2], $ids[1], $ids[0], $ids[3]], array_column($video->cut, 'shot'));
        $this->assertSame(4, MusicVideoTake::query()->where('music_video_id', $id)->count());
    }

    public function test_a_singing_shot_moved_off_its_lines_needs_a_new_clip(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->json('data.id');
        $ids = array_column($this->postJson("/api/creator/videos/{$id}/render")->json('data.shots'), 'id');

        $this->putJson("/api/creator/videos/{$id}/shots", ['shots' => $this->slots([$ids[1], $ids[0], $ids[2], $ids[3]])])
            ->assertAccepted()
            ->assertJsonPath('data.shots.0.rendered', false)
            ->assertJsonPath('data.shots.1.rendered', true);

        $this->postJson("/api/creator/videos/{$id}/render")
            ->assertJsonPath('data.status', MusicVideo::READY);

        $this->assertSame(790 - 10, Credits::for($this->owner)->total());
    }

    public function test_still_only_shots_move_over_their_still(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->json('data.id');

        $this->postJson("/api/creator/videos/{$id}/render");

        MusicVideoShot::query()
            ->where('music_video_id', $id)
            ->where('position', 1)
            ->update(['motion' => 'zoom_in']);
        MusicVideoShot::query()
            ->where('music_video_id', $id)
            ->where('position', 3)
            ->update(['motion' => 'pan', 'take_id' => null]);

        $this->postJson("/api/creator/videos/{$id}/render")
            ->assertJsonPath('data.status', MusicVideo::READY)
            ->assertJsonPath('data.shots.0.motion', 'zoom_in')
            ->assertJsonPath('data.shots.0.clipUrl', null);

        $this->assertSame(790, Credits::for($this->owner)->total());
        $this->assertEqualsWithDelta(12.0, $this->duration(MusicVideo::query()->findOrFail($id)->file), 0.2);
    }

    public function test_a_failed_save_keeps_the_ready_video(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->json('data.id');

        $this->postJson("/api/creator/videos/{$id}/render");

        $file = MusicVideo::query()
            ->findOrFail($id)
            ->file;

        MusicVideoShot::query()
            ->where('music_video_id', $id)
            ->where('position', 2)
            ->update(['take_id' => null]);

        $this->clipsFail = true;

        $this->postJson("/api/creator/videos/{$id}/render")
            ->assertAccepted();

        $video = MusicVideo::query()
            ->findOrFail($id);

        $this->assertSame(MusicVideo::READY, $video->status);
        $this->assertSame($file, $video->file);
        $this->assertSame(MusicVideo::CREDITS, $video->billing);
        $this->assertNotNull($video->error);
        $this->assertSame(790, Credits::for($this->owner)->total());
    }

    public function test_plan_video_time_is_counted_in_seconds(): void
    {
        $owner = $this->subscriber([Feature::AI_TOOLS->value => true, Feature::AI_CREDITS->value => 1000, Feature::VIDEO_MINUTES->value => 2]);
        $track = $this->track($owner);
        Sanctum::actingAs($owner);

        $id = $this->postJson("/api/creator/tracks/{$track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->json('data.id');

        $this->postJson("/api/creator/videos/{$id}/render")
            ->assertJsonPath('data.billing', MusicVideo::MINUTES);

        MusicVideoShot::query()
            ->where('music_video_id', $id)
            ->where('position', 2)
            ->update(['take_id' => null]);

        $this->postJson("/api/creator/videos/{$id}/render")
            ->assertJsonPath('data.billing', MusicVideo::MINUTES);

        $this->assertSame(980, Credits::for($owner)->total());
        $this->getJson("/api/creator/videos/{$id}")
            ->assertOk()
            ->assertJsonPath('secondsLeft', 57)
            ->assertJsonPath('minutesLeft', 0);

        MusicVideo::query()
            ->whereKey($id)
            ->delete();

        $this->getJson('/api/creator/videos')
            ->assertOk()
            ->assertJsonPath('secondsLeft', 57);
    }

    public function test_shots_can_be_changed_before_rendering(): void
    {
        Sanctum::actingAs($this->owner);

        $video = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->json('data');

        $this->putJson("/api/creator/videos/{$video['id']}/shots/{$video['shots'][0]['id']}", ['prompt' => 'A quiet harbour at dawn'])
            ->assertAccepted()
            ->assertJsonPath('data.prompt', 'A quiet harbour at dawn');
    }

    public function test_a_prompt_change_keeps_the_old_take_and_drops_the_oldest(): void
    {
        Sanctum::actingAs($this->owner);

        $video = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->json('data');
        $shot = MusicVideoShot::query()
            ->findOrFail($video['shots'][0]['id']);
        $disk = Storage::disk(config('filesystems.default'));
        $files = [];

        foreach (range(1, 5) as $i) {
            $files[] = $file = "ai/videos/{$video['id']}/take-{$i}.mp4";

            $disk->put($file, 'clip');

            $take = MusicVideoTake::query()
                ->create(['music_video_id' => $video['id'], 'shot_id' => $shot->id, 'file' => $file, 'length' => 3]);
        }

        $shot
            ->forceFill(['take_id' => $take->id])
            ->save();

        $this->putJson("/api/creator/videos/{$video['id']}/shots/{$shot->id}", ['prompt' => 'A quiet harbour at dawn'])
            ->assertAccepted()
            ->assertJsonPath('data.rendered', false)
            ->assertJsonPath('data.takeId', null);

        $this->assertSame(3, MusicVideoTake::query()->where('shot_id', $shot->id)->count());
        $this->assertFalse($disk->exists($files[0]));
        $this->assertFalse($disk->exists($files[1]));
        $this->assertTrue($disk->exists($files[4]));
    }

    public function test_one_still_can_be_redrawn_for_the_shots_share_of_the_storyboard_rate(): void
    {
        Sanctum::actingAs($this->owner);

        $video = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->json('data');
        $shot = MusicVideoShot::query()
            ->findOrFail($video['shots'][2]['id']);
        $old = $shot->keyframe;
        $disk = Storage::disk(config('filesystems.default'));

        $this->postJson("/api/creator/videos/{$video['id']}/shots/{$shot->id}/still")
            ->assertAccepted()
            ->assertJsonPath('data.drawing', true);

        $shot->refresh();

        $this->assertFalse($shot->drawing);
        $this->assertTrue($shot->needsClip());
        $this->assertNotSame($old, $shot->keyframe);
        $this->assertFalse($disk->exists($old));
        $this->assertTrue($disk->exists($shot->keyframe));
        // 3 seconds at 20 credits a minute
        $this->assertSame(979, Credits::for($this->owner)->total());
        Http::assertSent(fn(Request $request) => str_ends_with($request->url(), '/seedream-4/predictions')
            && str_contains($request['input']['prompt'], 'Cars streaking')
            && str_contains($request['input']['prompt'], 'The driver is the person on reference sheet 1'));
    }

    public function test_a_failed_still_is_refunded_and_a_drawing_shot_holds_the_render(): void
    {
        Sanctum::actingAs($this->owner);

        $video = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->json('data');
        $shot = MusicVideoShot::query()
            ->findOrFail($video['shots'][0]['id']);
        $old = $shot->keyframe;

        $this->stillsDown = 'city skyline';

        $this->postJson("/api/creator/videos/{$video['id']}/shots/{$shot->id}/still")
            ->assertAccepted();

        $shot->refresh();

        $this->assertSame($old, $shot->keyframe);
        $this->assertFalse($shot->drawing);
        $this->assertSame(980, Credits::for($this->owner)->total());

        $shot
            ->forceFill(['drawing' => true])
            ->save();

        $this->postJson("/api/creator/videos/{$video['id']}/render")
            ->assertUnprocessable();
        $this->postJson("/api/creator/videos/{$video['id']}/shots/{$shot->id}/still")
            ->assertUnprocessable();
    }

    public function test_creators_can_use_their_own_clips_and_images_in_shots(): void
    {
        Sanctum::actingAs($this->owner);

        $video = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->json('data');
        $singing = $video['shots'][1]['id'];
        $scene = $video['shots'][2]['id'];
        $clip = new UploadedFile($this->ffmpeg('mov', ['-f', 'lavfi', '-i', 'testsrc=size=320x240:rate=30:duration=5', '-pix_fmt', 'yuv420p']), 'clip.mov', 'video/quicktime', null, true);
        $image = new UploadedFile($this->ffmpeg('png', ['-f', 'lavfi', '-i', 'color=c=red:s=800x800', '-frames:v', '1']), 'still.png', 'image/png', null, true);

        $this->post("/api/creator/videos/{$video['id']}/shots/{$singing}/takes", ['file' => $clip, 'consent' => true], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->owner = $this->subscriber([Feature::AI_TOOLS->value => true, Feature::AI_CREDITS->value => 1000, Feature::VIDEO_OWN_PHOTOS->value => true]);
        MusicVideo::query()
            ->whereKey($video['id'])
            ->update(['user_id' => $this->owner->id]);
        Sanctum::actingAs($this->owner);

        $this->post("/api/creator/videos/{$video['id']}/shots/{$singing}/takes", ['file' => $clip], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('consent');

        $this->post("/api/creator/videos/{$video['id']}/shots/{$singing}/takes", ['file' => $clip, 'consent' => true], ['Accept' => 'application/json'])
            ->assertAccepted()
            ->assertJsonPath('data.shots.1.rendered', true)
            ->assertJsonPath('data.takes.0.source', MusicVideoTake::UPLOAD)
            ->assertJsonPath('data.takes.0.syncedFrom', null);

        $take = MusicVideoTake::query()
            ->where('shot_id', $singing)
            ->sole();

        $this->assertEqualsWithDelta(5.0, $take->length, 0.1);
        $this->assertStringContainsString('h264', $this->probe($take->file));

        $old = MusicVideoShot::query()
            ->findOrFail($scene)
            ->keyframe;

        $this->post("/api/creator/videos/{$video['id']}/shots/{$scene}/takes", ['file' => $image, 'consent' => true], ['Accept' => 'application/json'])
            ->assertAccepted()
            ->assertJsonPath('data.shots.2.motion', 'zoom_in')
            ->assertJsonPath('data.shots.2.rendered', true)
            ->assertJsonPath('data.edited', true);

        $this->assertNotSame($old, MusicVideoShot::query()->findOrFail($scene)->keyframe);
        $this->assertSame(1000, Credits::for($this->owner)->total());
    }

    public function test_deleting_a_track_deletes_its_video_files(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->json('data.id');
        $disk = Storage::disk(config('filesystems.default'));

        $this->assertNotEmpty($disk->allFiles("ai/videos/{$id}"));

        $this->deleteJson("/api/creator/releases/{$this->track->release_id}/tracks/{$this->track->id}")
            ->assertAccepted();

        $this->assertNull(MusicVideo::query()->find($id));
        $this->assertSame([], $disk->allFiles("ai/videos/{$id}"));

        $track = $this->track($this->owner);
        $other = $this->postJson("/api/creator/tracks/{$track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->json('data.id');

        $track->release->delete();

        $this->assertSame([], $disk->allFiles("ai/videos/{$other}"));
    }

    public function test_files_of_videos_that_no_longer_exist_are_pruned(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->json('data.id');
        $disk = Storage::disk(config('filesystems.default'));
        $gone = MusicVideo::query()->max('id') + 100;

        $disk->put("ai/videos/{$gone}/video.mp4", 'video');

        $this->artisan('videos:prune-files')
            ->assertSuccessful();

        $this->assertFalse($disk->exists("ai/videos/{$gone}/video.mp4"));
        $this->assertNotEmpty($disk->allFiles("ai/videos/{$id}"));
    }

    public function test_shots_can_be_reordered_into_the_same_time_slots(): void
    {
        Sanctum::actingAs($this->owner);

        $video = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->json('data');
        $ids = array_column($video['shots'], 'id');

        foreach (MusicVideoShot::query()->whereKey($ids)->get() as $shot) {
            $performance = $shot->kind === MusicVideoShot::PERFORMANCE;
            $take = MusicVideoTake::query()
                ->create([
                    'music_video_id' => $video['id'], 'shot_id' => $shot->id, 'file' => 'ai/videos/old.mp4', 'length' => $shot->length(),
                    'synced_from' => $performance ? $shot->start : null, 'synced_to' => $performance ? $shot->end : null,
                ]);

            $shot
                ->forceFill(['take_id' => $take->id])
                ->save();
        }

        $order = [$ids[1], $ids[0], $ids[2], $ids[3]];

        $this->putJson("/api/creator/videos/{$video['id']}/shots", ['shots' => $this->slots($order)])
            ->assertAccepted()
            ->assertJsonPath('data.shots.0.id', $ids[1])
            ->assertJsonPath('data.shots.0.start', 0)
            ->assertJsonPath('data.shots.0.rendered', false)
            ->assertJsonPath('data.shots.1.id', $ids[0])
            ->assertJsonPath('data.shots.1.start', 3)
            ->assertJsonPath('data.shots.1.rendered', true)
            ->assertJsonPath('data.shots.2.id', $ids[2])
            ->assertJsonPath('data.shots.2.rendered', true);

        $this->assertSame(
            [1, 2, 3, 4],
            MusicVideoShot::query()
                ->whereKey($order)
                ->orderBy('position')
                ->pluck('position')
                ->all(),
        );

        $this->putJson("/api/creator/videos/{$video['id']}/shots", ['shots' => $this->slots([$ids[0], $ids[1]])])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shots');
        $this->putJson("/api/creator/videos/{$video['id']}/shots", ['shots' => [
            ['id' => $ids[0], 'start' => 0, 'end' => 3], ['id' => $ids[1], 'start' => 4, 'end' => 12],
        ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shots.1');
        $this->putJson("/api/creator/videos/{$video['id']}/shots", ['shots' => [
            ['id' => $ids[0], 'start' => 0, 'end' => 6], ['id' => $ids[0], 'start' => 6, 'end' => 12],
        ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shots.1');
    }

    public function test_the_editor_trims_splits_merges_and_swaps_shots_and_saves_for_free(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->json('data.id');
        $shots = $this->postJson("/api/creator/videos/{$id}/render")
            ->json('data.shots');
        [$a, $b, $c, $d] = $shots;

        // Split the first b-roll, trim the singing shot shorter, play the
        // first b-roll take in the third shot and the last as a moving still.
        $video = $this->putJson("/api/creator/videos/{$id}/shots", ['shots' => [
            ['id' => $a['id'], 'start' => 0, 'end' => 1.5],
            ['copy_of' => $a['id'], 'start' => 1.5, 'end' => 3.5, 'offset' => 1],
            ['id' => $b['id'], 'start' => 3.5, 'end' => 6],
            ['id' => $c['id'], 'start' => 6, 'end' => 9, 'take_id' => $a['takeId']],
            ['id' => $d['id'], 'start' => 9, 'end' => 12, 'motion' => 'zoom_out'],
        ]])
            ->assertAccepted()
            ->assertJsonPath('data.edited', true)
            ->assertJsonCount(5, 'data.shots')
            ->json('data');

        $this->assertSame([true, true, true, true, true], array_column($video['shots'], 'rendered'));
        $this->assertSame(0.5, $video['shots'][2]['offset']);
        $this->assertSame($a['takeId'], $video['shots'][1]['takeId']);
        $this->assertNotEmpty($video['takes']);

        $this->postJson("/api/creator/videos/{$id}/render")
            ->assertJsonPath('data.status', MusicVideo::READY)
            ->assertJsonPath('data.edited', false);

        $this->assertSame(790, Credits::for($this->owner)->total());
        $this->assertEqualsWithDelta(12.0, $this->duration(MusicVideo::query()->findOrFail($id)->file), 0.2);
    }

    public function test_unsaved_changes_can_be_discarded(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->json('data.id');
        $shots = $this->postJson("/api/creator/videos/{$id}/render")
            ->json('data.shots');

        $this->deleteJson("/api/creator/videos/{$id}/edit")
            ->assertAccepted();

        $this->putJson("/api/creator/videos/{$id}/shots", ['shots' => [
            ['id' => $shots[0]['id'], 'start' => 0, 'end' => 6],
            ['id' => $shots[3]['id'], 'start' => 6, 'end' => 12],
        ]])
            ->assertAccepted();
        $this->putJson("/api/creator/videos/{$id}/shots/{$shots[3]['id']}", ['prompt' => 'A quiet harbour at dawn'])
            ->assertAccepted();

        $this->deleteJson("/api/creator/videos/{$id}/edit")
            ->assertAccepted()
            ->assertJsonPath('data.edited', false)
            ->assertJsonCount(4, 'data.shots');

        $video = $this->getJson("/api/creator/videos/{$id}")
            ->json('data');

        $this->assertSame(array_column($shots, 'id'), array_column($video['shots'], 'id'));
        $this->assertSame(array_column($shots, 'takeId'), array_column($video['shots'], 'takeId'));
        $this->assertSame(array_column($shots, 'prompt'), array_column($video['shots'], 'prompt'));
        $this->assertSame([true, true, true, true], array_column($video['shots'], 'rendered'));
        $this->assertTrue(collect($video['shots'])->every(fn(array $shot) => $shot['keyframeUrl'] !== null));
    }

    public function test_listeners_get_the_owners_finished_video_for_a_track(): void
    {
        $this->getJson("/api/tracks/{$this->track->slug}/video")
            ->assertOk()
            ->assertExactJson(['data' => [], 'message' => 'Data Fetched.', 'status' => 'success', 'status_code' => 200]);

        $video = MusicVideo::query()->forceCreate([
            'user_id' => $this->owner->id, 'track_id' => $this->track->id, 'status' => MusicVideo::READY,
            'ratio' => '9:16', 'duration' => 12, 'file' => 'ai/videos/1/video.mp4',
        ]);

        $this->getJson("/api/tracks/{$this->track->slug}/video")
            ->assertOk()
            ->assertJsonPath('data.id', $video->id)
            ->assertJsonPath('data.ratio', '9:16')
            ->assertJsonPath('data.videoUrl', fn($url) => is_string($url) && $url !== '');
    }

    public function test_own_photos_need_the_plan_feature_and_consent(): void
    {
        Sanctum::actingAs($this->owner);
        $photo = UploadedFile::fake()->image('me.jpg', 800, 800);

        $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'photo' => $photo])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('consent');

        $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'photo' => $photo, 'consent' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo');

        $this->assertSame(0, MusicVideo::query()->where('user_id', $this->owner->id)->count());
    }

    public function test_only_the_track_owner_can_make_its_video_and_only_the_maker_can_see_it(): void
    {
        $other = $this->subscriber([Feature::AI_TOOLS->value => true, Feature::AI_CREDITS->value => 1000]);
        Sanctum::actingAs($this->owner);

        $id = $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->json('data.id');

        Sanctum::actingAs($other);

        $this->postJson("/api/creator/tracks/{$this->track->slug}/videos", ['mode' => 'legacy', 'mode' => 'legacy'])
            ->assertForbidden();
        $this->getJson("/api/creator/videos/{$id}")
            ->assertForbidden();
    }

    public function test_creators_pick_from_their_own_tracks(): void
    {
        $this->track($this->subscriber([]));

        Sanctum::actingAs($this->owner);

        $this->getJson('/api/creator/tracks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->track->id);
        $this->getJson('/api/creator/tracks?search=nothing-like-this')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_shot_lists_keep_shots_between_the_shortest_and_longest_length(): void
    {
        $shots = (new ShotList(30, 100, 8))->build([
            ['start' => 0, 'kind' => 'broll', 'prompt' => 'Open'],
            ['start' => 0.8, 'kind' => 'performance', 'prompt' => 'Too soon'],
            ['start' => 4, 'kind' => 'performance', 'prompt' => 'Sing'],
        ]);

        $lengths = array_map(fn(array $shot) => $shot['end'] - $shot['start'], $shots);

        $this->assertSame(0.0, $shots[0]['start']);
        $this->assertSame(30.0, end($shots)['end']);
        $this->assertGreaterThanOrEqual(ShotList::MIN_SECONDS, min($lengths));
        $this->assertLessThanOrEqual(8.0, max($lengths));
        $this->assertSame('Open', $shots[0]['prompt']);
        $this->assertSame(MusicVideoShot::PERFORMANCE, end($shots)['kind']);
    }

    /**
     * @param  array<string, bool|int>  $features
     */
    protected function subscriber(array $features): User
    {
        $plan = Plan::create([
            'slug' => 'video-' . uniqid(), 'name' => 'Video', 'price' => 1000, 'interval' => 'month',
            'features' => $features,
        ]);
        $user = User::factory()
            ->create();

        $user
            ->subscriptions()
            ->create([
                'plan' => $plan->slug, 'plan_id' => $plan->id, 'amount' => 1000,
                'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(),
            ]);

        return $user->fresh();
    }

    /**
     * A released 12 second track at 120 BPM owned by the user.
     */
    protected function track(User $owner): Track
    {
        $release = Release::query()
            ->forceCreate([
                'user_id' => $owner->id,
                'title' => 'Album',
                'slug' => 'album-' . uniqid(),
                'draft' => false,
            ]);

        $media = new Media;
        $name = 'video-' . uniqid() . '.mp3';
        $audio = $this->ffmpeg('mp3', ['-f', 'lavfi', '-i', 'aevalsrc=0.3*sin(220*2*PI*t):s=44100:d=12']);
        $path = $media->mediaInfo('music_tracks', $name)['path'];
        $bytes = (string) file_get_contents($audio);

        $media->disk->put($path, $bytes);

        $track = new Track;
        $track->forceFill([
            'release_id' => $release->id,
            'title' => 'Night Drive',
            'slug' => 'night-drive-' . uniqid(),
            'audio' => $name,
            'duration' => 12,
            'bpm' => 120,
        ]);
        $track->saveQuietly();

        return $track;
    }

    protected function fakeReplicate(): void
    {
        $still = (string) file_get_contents($this->ffmpeg('png', ['-f', 'lavfi', '-i', 'color=c=purple:s=1344x768', '-frames:v', '1']));
        $clip = (string) file_get_contents($this->ffmpeg('mp4', ['-f', 'lavfi', '-i', 'testsrc=size=640x360:rate=24:duration=2', '-pix_fmt', 'yuv420p']));
        $vocals = (string) file_get_contents($this->ffmpeg('wav', ['-f', 'lavfi', '-i', 'aevalsrc=0.3*sin(440*2*PI*t):s=44100:d=12']));

        Http::fake(function (Request $request) use ($still, $clip, $vocals) {
            $url = $request->url();

            return match (true) {
                $url === self::REPLICATE . '/files' => Http::response(['urls' => ['get' => self::REPLICATE . '/files/f1']], 201),
                str_ends_with($url, '/seedream-4/predictions') && $this->stillOutages-- > 0 => Http::response('error code: 502', 502),
                str_ends_with($url, '/seedream-4/predictions') && $this->stillsDown && str_contains($request['input']['prompt'], $this->stillsDown) => Http::response('error code: 502', 502),
                str_ends_with($url, '/seedream-4/predictions') => Http::response(['id' => 's1', 'status' => 'succeeded', 'output' => [self::CDN . '/still.png']], 201),
                $url === self::REPLICATE . '/predictions' => Http::response(['id' => 'd1', 'status' => 'succeeded', 'output' => ['vocals' => self::CDN . '/vocals.wav']], 201),
                str_contains($url, '/prunaai/') && $this->clipsFail => Http::response(['id' => 'c1', 'status' => 'failed', 'error' => 'boom'], 201),
                str_contains($url, '/prunaai/') => Http::response(['id' => 'c1', 'status' => 'succeeded', 'output' => self::CDN . '/clip.mp4'], 201),
                $url === self::CDN . '/still.png' => Http::response($still),
                $url === self::CDN . '/clip.mp4' => Http::response($clip),
                $url === self::CDN . '/vocals.wav' => Http::response($vocals),
                default => Http::response(['detail' => "Unexpected {$url}"], 500),
            };
        });
    }

    /**
     * The shots in this order, each in the 3 second slot of its place.
     *
     * @param  list<int>  $ids
     * @return list<array{id: int, start: int, end: int}>
     */
    protected function slots(array $ids): array
    {
        return array_map(fn(int $id, int $i) => ['id' => $id, 'start' => $i * 3, 'end' => $i * 3 + 3], $ids, array_keys($ids));
    }

    /**
     * Streams and length of a stored video, as ffprobe prints them.
     */
    protected function probe(string $file): string
    {
        $local = $this->path('mp4');
        $content = Storage::disk(config('filesystems.default'))
            ->get($file);

        file_put_contents($local, $content);

        $probe = new Process(['ffprobe', '-v', 'error', '-show_entries', 'format=duration:stream=width,height,codec_name', '-of', 'csv=p=0', $local]);
        $probe->mustRun();

        return $probe->getOutput();
    }

    protected function duration(string $file): float
    {
        $lines = explode("\n", trim($this->probe($file)));

        return (float) end($lines);
    }

    /**
     * A temporary file made by FFmpeg from these input arguments.
     *
     * @param  list<string>  $args
     */
    protected function ffmpeg(string $ext, array $args): string
    {
        $path = $this->path($ext);

        (new Process(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', ...$args, $path]))
            ->mustRun();

        return $path;
    }

    protected function path(string $ext): string
    {
        $base = tempnam(sys_get_temp_dir(), 'mv-');
        @unlink($base);
        $path = "{$base}.{$ext}";
        $this->temp[] = $path;

        return $path;
    }
}
