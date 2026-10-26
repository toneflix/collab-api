<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Models\AiJob;
use App\Models\Plan;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Credits;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiArtworkTest extends TestCase
{
    use DatabaseTransactions;

    protected const REPLICATE = 'https://replicate.test/v1';

    protected const CLOUDFLARE = 'https://cloudflare.test/client/v4/accounts/acc1';

    protected const CDN = 'https://replicate.delivery/art';

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        config([
            'ai.providers.replicate.key' => 'r8_test',
            'ai.providers.replicate.url' => self::REPLICATE,
            'ai.providers.cloudflare.account' => 'acc1',
            'ai.providers.cloudflare.key' => 'cf_test',
            'ai.providers.cloudflare.url' => 'https://cloudflare.test/client/v4',
        ]);

        AiSettings::update(['ai_enabled' => true, 'ai_artwork_credits' => 3, 'ai_artwork_images' => 2]);

        $plan = Plan::create([
            'slug' => 'ai-' . uniqid(), 'name' => 'AI', 'price' => 1000, 'interval' => 'month',
            'features' => [Feature::AI_TOOLS->value => true, Feature::AI_CREDITS->value => 100],
        ]);
        $this->owner = User::factory()
            ->create();
        $this->owner
            ->subscriptions()
            ->create([
                'plan' => $plan->slug, 'plan_id' => $plan->id, 'amount' => 1000,
                'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(),
            ]);
        $this->owner = $this->owner->fresh();
    }

    protected function tearDown(): void
    {
        AiSettings::flush();

        parent::tearDown();
    }

    /**
     * A plain PNG of this size.
     */
    protected function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 40, 90));

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    protected function fakeReplicate(int $width, int $height): void
    {
        Http::fake([
            self::REPLICATE . '/models/black-forest-labs/flux-schnell/predictions' => Http::response([
                'id' => 'p1',
                'status' => 'succeeded',
                'output' => [self::CDN . '/1.png', self::CDN . '/2.png'],
            ], 201),
            self::CDN . '/*' => Http::response($this->png($width, $height), 200, ['Content-Type' => 'image/png']),
        ]);
    }

    public function test_release_artwork_is_drawn_as_square_images_to_pick_from(): void
    {
        $this->fakeReplicate(1024, 1024);
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/creator/ai/artwork', [
            'kind' => 'release',
            'prompt' => 'Neon city at night',
            'title' => 'Lagos Lights',
            'artist' => 'Obi',
            'genre' => 'Afrobeats',
        ])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'artwork')
            ->assertJsonPath('data.artworkKind', 'release')
            ->assertJsonPath('data.credits', 3)
            ->assertJsonPath('data.trackId', null);

        $job = AiJob::findOrFail($response->json('data.id'));
        $this->assertSame(AiJob::SUCCEEDED, $job->status);
        $this->assertCount(2, $job->output['images']);
        $this->assertSame(97, Credits::for($this->owner)->total());

        Http::assertSent(fn(Request $request) => str_ends_with($request->url(), '/flux-schnell/predictions')
            && $request['input']['aspect_ratio'] === '1:1'
            && $request['input']['num_outputs'] === 2
            && str_contains($request['input']['prompt'], 'Album cover art, titled "Lagos Lights", by Obi, in the Afrobeats genre.')
            && str_contains($request['input']['prompt'], 'Neon city at night.'));

        $images = $this->getJson("/api/creator/ai/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.images.0.width', 1024)
            ->assertJsonPath('data.images.0.height', 1024)
            ->assertJsonMissingPath('data.images.0.file')
            ->json('data.images');

        $this->get($images[1]['url'])
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->get(route('ai.jobs.artwork.image', ['job' => $job->id, 'image' => 0]))
            ->assertForbidden();
    }

    public function test_artist_covers_are_cropped_wide(): void
    {
        $this->fakeReplicate(1344, 768);
        Sanctum::actingAs($this->owner);

        $id = $this->postJson('/api/creator/ai/artwork', ['kind' => 'artist_cover', 'prompt' => 'Sunset over the sea'])
            ->assertCreated()
            ->json('data.id');

        $image = AiJob::findOrFail($id)->output['images'][0];
        $this->assertSame([1344, 672], [$image['width'], $image['height']]);

        Http::assertSent(fn(Request $request) => ($request['input']['aspect_ratio'] ?? null) === '21:9');
    }

    public function test_artwork_runs_on_workers_ai_when_admins_pick_it(): void
    {
        AiSettings::update(['ai_artwork_engine' => 'cloudflare']);
        config(['ai.providers.replicate.key' => null]);
        Http::fake([
            self::CLOUDFLARE . '/ai/run/@cf/black-forest-labs/flux-1-schnell' => Http::response([
                'success' => true,
                'result' => ['image' => base64_encode($this->png(1024, 1024))],
            ]),
        ]);
        Sanctum::actingAs($this->owner);

        $id = $this->postJson('/api/creator/ai/artwork', ['kind' => 'project', 'prompt' => 'Abstract waves'])
            ->assertCreated()
            ->assertJsonPath('data.model', null)
            ->json('data.id');

        $job = AiJob::findOrFail($id);
        $this->assertSame(AiJob::SUCCEEDED, $job->status);
        $this->assertCount(2, $job->output['images']);
        Http::assertSentCount(2);
    }

    public function test_a_failed_drawing_refunds_the_credits(): void
    {
        Http::fake([
            self::REPLICATE . '/*' => Http::response(['id' => 'p1', 'status' => 'failed', 'error' => 'NSFW content detected'], 201),
        ]);
        Sanctum::actingAs($this->owner);

        $id = $this->postJson('/api/creator/ai/artwork', ['kind' => 'track', 'prompt' => 'Something'])
            ->assertCreated()
            ->json('data.id');

        $job = AiJob::findOrFail($id);
        $this->assertSame(AiJob::FAILED, $job->status);
        $this->assertStringNotContainsString('flux', (string) $job->error);
        $this->assertSame(100, Credits::for($this->owner)->total());
    }

    public function test_people_list_only_their_own_artwork_jobs_of_a_kind(): void
    {
        $this->fakeReplicate(1024, 1024);
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/creator/ai/artwork', ['kind' => 'release', 'prompt' => 'Neon city'])
            ->assertCreated();
        $this->postJson('/api/creator/ai/artwork', ['kind' => 'artist', 'prompt' => 'Studio portrait'])
            ->assertCreated();

        $someone = User::factory()
            ->create();

        AiJob::query()
            ->create(['user_id' => $someone->id, 'kind' => 'artwork', 'input' => ['artwork_kind' => 'release', 'prompt' => 'x']]);

        $this->getJson('/api/creator/ai/artwork?kind=release')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.prompt', 'Neon city')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_old_artwork_images_are_swept(): void
    {
        $this->fakeReplicate(1024, 1024);
        Sanctum::actingAs($this->owner);

        $id = $this->postJson('/api/creator/ai/artwork', ['kind' => 'song', 'prompt' => 'Rainy window'])
            ->json('data.id');

        $job = AiJob::findOrFail($id);
        $file = $job->output['images'][0]['file'];
        $disk = Storage::disk(config('filesystems.default'));

        $disk->assertExists($file);

        $job
            ->forceFill(['finished_at' => now()->subDays(8)])
            ->save();

        $this->artisan('ai:sweep')
            ->assertSuccessful();

        $disk->assertMissing($file);
        $this->getJson("/api/creator/ai/jobs/{$id}")
            ->assertJsonPath('data.images', null);
    }
}
