<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Enums\Feature;
use App\Models\Configuration;
use App\Models\Plan;
use App\Models\FreesoundConnection;
use App\Models\Genre;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\Sound;
use App\Models\User;
use App\Services\Sounds\Freesound;
use App\Services\Sounds\SoundLibrary;
use App\Services\Sounds\SoundSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SoundsTest extends TestCase
{
    use DatabaseTransactions;

    protected const BASE = 'https://freesound.test/apiv2';

    protected const CC0 = 'http://creativecommons.org/publicdomain/zero/1.0/';

    protected const CC_BY = 'https://creativecommons.org/licenses/by/4.0/';

    protected User $owner;

    protected User $stranger;

    protected Project $project;

    protected ProjectTrack $track;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        config(['services.freesound.base_url' => self::BASE, 'services.freesound.download_delay' => 0]);
        SoundSettings::flush();
        Cache::forget('sounds::sync-running');

        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->project = Project::query()->forceCreate([
            'user_id' => $this->owner->id,
            'title' => 'Sounds test',
            'privacy' => 'private',
        ]);
        $this->track = $this->project->tracks()->forceCreate(['title' => 'Beat', 'privacy' => 'private']);
    }

    protected function tearDown(): void
    {
        SoundSettings::flush();

        parent::tearDown();
    }

    protected function admin(): User
    {
        Permission::findOrCreate('manage-configuration', 'web');
        $admin = User::factory()->create();
        $admin->givePermissionTo('manage-configuration');

        return $admin;
    }

    protected function setStorage(int $mb): void
    {
        $plan = Plan::default();
        $plan->forceFill(['features' => [...($plan->features ?? []), Feature::MAX_STORAGE->value => $mb]])->save();
    }

    protected function configureFreesound(array $more = []): void
    {
        SoundSettings::update([
            'freesound_enabled' => '1',
            'freesound_client_id' => 'client-123',
            'freesound_client_secret' => 'secret-456',
            ...$more,
        ]);
    }

    protected function librarySound(array $attributes = []): Sound
    {
        return SoundLibrary::store(UploadedFile::fake()->create('loop.mp3', 20, 'audio/mpeg'), [
            'name' => 'Loop',
            'duration' => 4,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    protected function fakeFreesound(array $results): void
    {
        Http::fake([
            self::BASE.'/search/text/*' => Http::response(['count' => count($results), 'results' => $results]),
            self::BASE.'/sounds/*/download/' => Http::response('ORIGINAL-AUDIO', 200, ['Content-Type' => 'audio/wav']),
            self::BASE.'/sounds/*' => function (ClientRequest $request) use ($results) {
                preg_match('#/sounds/(\d+)/#', $request->url(), $m);
                $found = collect($results)->firstWhere('id', (int) $m[1]);

                return $found ? Http::response($found) : Http::response(['detail' => 'Not found'], 404);
            },
            'https://cdn.freesound.test/*' => Http::response('PREVIEW-AUDIO', 200, ['Content-Type' => 'audio/mpeg']),
            self::BASE.'/oauth2/access_token/' => Http::response(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 86399]),
            self::BASE.'/me/' => Http::response(['username' => 'obi_fs']),
        ]);
    }

    protected function fsResult(int $id, string $license = self::CC0, array $more = []): array
    {
        return [
            'id' => $id,
            'name' => "Loop {$id} 120bpm.wav",
            'tags' => ['drum', 'loop'],
            'username' => 'maker',
            'license' => $license,
            'duration' => 4.5,
            'type' => 'wav',
            'filesize' => 1000,
            'url' => "https://freesound.test/s/{$id}/",
            'previews' => ['preview-hq-mp3' => "https://cdn.freesound.test/{$id}-hq.mp3"],
            ...$more,
        ];
    }

    public function test_the_library_stays_hidden_while_off_or_empty(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/sounds/status')->assertOk()->assertJsonPath('data.library', false);
        $this->getJson('/api/sounds/library')->assertNotFound();

        SoundSettings::update(['sounds_library_enabled' => '1']);
        $this->getJson('/api/sounds/status')->assertJsonPath('data.library', false);
        $this->getJson('/api/initialize')->assertJsonPath('data.sounds.library', false);

        $this->librarySound(['category' => 'Drums']);
        $this->getJson('/api/sounds/status')
            ->assertJsonPath('data.library', true)
            ->assertJsonPath('data.categories', ['Drums']);
        $this->getJson('/api/sounds/library')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_hidden_library_sounds_are_not_listed(): void
    {
        SoundSettings::update(['sounds_library_enabled' => '1']);
        $this->librarySound(['name' => 'Shown']);
        $this->librarySound(['name' => 'Hidden', 'is_active' => false]);

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/sounds/library')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Shown');
    }

    public function test_people_can_upload_their_own_sounds_within_their_storage(): void
    {
        $this->setStorage(1);
        Sanctum::actingAs($this->owner);

        $this->post('/api/sounds/mine', [
            'file' => UploadedFile::fake()->create('my-loop.mp3', 600, 'audio/mpeg'),
            'duration' => 3.2,
            'waveform' => json_encode([0.2, 0.9, 1.4]),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'my-loop')
            ->assertJsonPath('data.waveform', [0.2, 0.9, 1])
            ->assertJsonPath('storage.limit', 1024 * 1024);

        // 600 KB used of 1 MB, so another 600 KB doesn't fit.
        $this->post('/api/sounds/mine', [
            'file' => UploadedFile::fake()->create('too-much.mp3', 600, 'audio/mpeg'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->getJson('/api/sounds/mine')->assertJsonCount(1, 'data');
        $this->getJson('/api/plans')->assertOk();
    }

    public function test_people_can_only_change_their_own_sounds(): void
    {
        Sanctum::actingAs($this->owner);
        $id = $this->post('/api/sounds/mine', ['file' => UploadedFile::fake()->create('a.mp3', 10, 'audio/mpeg')], ['Accept' => 'application/json'])
            ->json('data.id');

        Sanctum::actingAs($this->stranger);
        $this->deleteJson("/api/sounds/mine/{$id}")->assertNotFound();
        $this->getJson('/api/sounds/mine')->assertJsonCount(0, 'data');

        Sanctum::actingAs($this->owner);
        $this->deleteJson("/api/sounds/mine/{$id}")->assertAccepted()->assertJsonPath('storage.used', 0);
        $this->assertDatabaseMissing('sounds', ['id' => $id]);
    }

    public function test_sounds_can_be_added_to_a_track_as_stems(): void
    {
        SoundSettings::update(['sounds_library_enabled' => '1']);
        $sound = $this->librarySound(['name' => 'Kick loop', 'author' => 'maker', 'license' => self::CC0, 'source' => 'freesound']);
        $url = "/api/creator/projects/{$this->project->id}/tracks/{$this->track->id}/sounds";

        Sanctum::actingAs($this->stranger);
        $this->postJson($url, ['sound_id' => $sound->id])->assertForbidden();

        Sanctum::actingAs($this->owner);
        $response = $this->postJson($url, ['sound_id' => $sound->id, 'start' => 8.5])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Kick loop')
            ->assertJsonPath('data.meta.start', 8.5)
            ->assertJsonPath('data.meta.sound.author', 'maker');

        $stem = $this->track->stems()->findOrFail($response->json('data.id'));
        $this->assertNotSame($sound->file, $stem->src);
        Storage::disk(config('filesystems.default'))->assertExists('public/media/music/stem/'.$stem->src);

        // Removing the stem keeps the library sound.
        $stem->delete();
        $this->assertTrue(Storage::disk(config('filesystems.default'))->exists('public/media/sounds/'.$sound->file));

        // With the library off, its sounds can't be added.
        SoundSettings::update(['sounds_library_enabled' => '0']);
        $this->postJson($url, ['sound_id' => $sound->id])->assertNotFound();
    }

    public function test_only_admins_see_sound_settings_and_the_secret_is_never_returned(): void
    {
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/admin/sounds')->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->putJson('/api/admin/sounds', [
            'sounds_library_enabled' => true,
            'freesound_enabled' => true,
            'freesound_client_id' => 'client-123',
            'freesound_client_secret' => 'secret-456',
            'freesound_sync_queries' => "Drums: drum loop\nbass loop",
        ])
            ->assertAccepted()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.fields.freesound_client_secret.value', null)
            ->assertJsonPath('data.fields.freesound_client_secret.isSet', true);

        $stored = Configuration::query()->toBase()->where('key', 'freesound_client_secret')->value('value');
        $this->assertNotSame('secret-456', $stored);
        $this->assertSame('secret-456', SoundSettings::get('freesound_client_secret'));
        $this->assertSame([
            ['category' => 'Drums', 'query' => 'drum loop'],
            ['category' => 'bass loop', 'query' => 'bass loop'],
        ], SoundSettings::syncQueries());

        // Saving without the secret keeps it.
        $this->putJson('/api/admin/sounds', ['freesound_client_id' => 'client-789'])->assertAccepted();
        $this->assertSame('secret-456', SoundSettings::get('freesound_client_secret'));

        $this->getJson('/api/initialize')->assertJsonMissing(['secret-456']);
    }

    public function test_sync_adds_only_cc0_sounds_and_skips_ones_it_has(): void
    {
        $this->configureFreesound(['freesound_sync_queries' => 'Drums: drum loop', 'sounds_library_enabled' => '1']);
        $this->fakeFreesound([$this->fsResult(1), $this->fsResult(2, self::CC_BY), $this->fsResult(3)]);

        $result = SoundLibrary::sync();

        $this->assertSame(2, $result['added']);
        $library = Sound::query()->library()->orderBy('source_id')->get();
        $this->assertSame(['1', '3'], $library->pluck('source_id')->all());
        $this->assertSame('Drums', $library[0]->category);
        $this->assertSame(120.0, $library[0]->bpm);
        // No connected account, so the MP3 preview is used.
        $this->assertSame('preview', $library[0]->quality);

        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/search/text/')
            && str_contains(urldecode($r->url()), 'license:"Creative Commons 0"'));

        $again = SoundLibrary::sync();
        $this->assertSame(0, $again['added']);
        $this->assertSame(2, $again['skipped']);
        $this->assertSame(2, Sound::query()->library()->count());
    }

    public function test_sync_downloads_originals_with_the_chosen_admin_account(): void
    {
        $admin = $this->admin();
        FreesoundConnection::query()->create(['user_id' => $admin->id, 'access_token' => 'admin-token', 'expires_at' => now()->addDay()]);
        $this->configureFreesound(['freesound_sync_queries' => 'kick', 'freesound_library_account' => (string) $admin->id]);
        $this->fakeFreesound([$this->fsResult(7)]);

        SoundLibrary::sync();

        $this->assertSame('original', Sound::query()->library()->value('quality'));
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/sounds/7/download/')
            && $r->hasHeader('Authorization', 'Bearer admin-token'));
    }

    public function test_admins_can_start_a_sync(): void
    {
        $this->configureFreesound(['freesound_sync_queries' => 'kick']);
        $this->fakeFreesound([$this->fsResult(9)]);

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/admin/sounds/sync')->assertAccepted();

        // The test queue runs jobs straight away.
        $this->assertSame(1, Sound::query()->library()->count());
        $this->getJson('/api/admin/sounds')->assertJsonPath('data.lastSync.added', 1);
    }

    public function test_freesound_search_only_returns_cc0_sounds(): void
    {
        $this->configureFreesound();
        $this->fakeFreesound([$this->fsResult(1), $this->fsResult(2, self::CC_BY)]);

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/sounds/freesound/search?query=kick')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', '1')
            ->assertJsonPath('data.0.cc0', true)
            ->assertJsonPath('data.0.imported', false);

        Http::assertSent(fn (ClientRequest $r) => str_contains(urldecode($r->url()), 'license:"Creative Commons 0"')
            && str_contains($r->url(), 'token=secret-456'));
    }

    public function test_freesound_is_hidden_while_off(): void
    {
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/sounds/freesound/search?query=kick')->assertNotFound();
        $this->getJson('/api/sounds/status')->assertJsonPath('data.freesound', false);
    }

    public function test_people_connect_freesound_with_a_one_time_state(): void
    {
        $this->configureFreesound();
        $this->fakeFreesound([]);

        Sanctum::actingAs($this->owner);
        $url = $this->getJson('/api/sounds/freesound/connect?return=/studio')->assertOk()->json('data.url');
        $this->assertStringStartsWith(self::BASE.'/oauth2/authorize/?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('client-123', $query['client_id']);

        // Someone else can't finish this person's sign-in.
        Sanctum::actingAs($this->stranger);
        $this->postJson('/api/sounds/freesound/callback', ['code' => 'abc', 'state' => $query['state']])->assertUnprocessable();

        // The state was used up by that attempt.
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/sounds/freesound/callback', ['code' => 'abc', 'state' => $query['state']])->assertUnprocessable();

        $url = $this->getJson('/api/sounds/freesound/connect?return=//evil.test')->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->postJson('/api/sounds/freesound/callback', ['code' => 'abc', 'state' => $query['state']])
            ->assertOk()
            ->assertJsonPath('data.username', 'obi_fs')
            ->assertJsonPath('data.return', null);

        $connection = $this->owner->freesound()->first();
        $this->assertSame('access-1', $connection->access_token);
        $this->assertNotSame('access-1', $connection->getRawOriginal('access_token'));
        $this->getJson('/api/sounds/status')->assertJsonPath('data.freesoundConnected', true);

        $this->deleteJson('/api/sounds/freesound/connect')->assertAccepted();
        $this->assertNull($this->owner->freesound()->first());
    }

    public function test_importing_needs_a_connection_and_only_takes_cc0(): void
    {
        $this->configureFreesound();
        $this->fakeFreesound([$this->fsResult(1), $this->fsResult(2, self::CC_BY)]);

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/sounds/freesound/import', ['id' => '1'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.connect.0', 'Connect Freesound first.');

        FreesoundConnection::query()->create(['user_id' => $this->owner->id, 'access_token' => 'user-token', 'expires_at' => now()->addDay()]);

        $this->postJson('/api/sounds/freesound/import', ['id' => '2'])->assertUnprocessable();

        $this->postJson('/api/sounds/freesound/import', ['id' => '1'])
            ->assertCreated()
            ->assertJsonPath('data.source', 'freesound')
            ->assertJsonPath('data.quality', 'original')
            ->assertJsonPath('data.mine', true);

        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/sounds/1/download/')
            && $r->hasHeader('Authorization', 'Bearer user-token'));

        // A second import of the same sound doesn't store it twice.
        $this->postJson('/api/sounds/freesound/import', ['id' => '1'])->assertOk();
        $this->assertSame(1, Sound::query()->where('user_id', $this->owner->id)->count());
    }

    public function test_imports_count_towards_storage(): void
    {
        $this->setStorage(1);
        $this->configureFreesound();
        $this->fakeFreesound([$this->fsResult(1, self::CC0, ['filesize' => 5 * 1024 * 1024])]);
        FreesoundConnection::query()->create(['user_id' => $this->owner->id, 'access_token' => 'user-token']);

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/sounds/freesound/import', ['id' => '1'])->assertUnprocessable()->assertJsonValidationErrors('id');
        $this->assertSame(0, Sound::query()->count());
    }

    public function test_expired_connections_are_refreshed(): void
    {
        $this->configureFreesound();
        $this->fakeFreesound([$this->fsResult(1)]);
        $connection = FreesoundConnection::query()->create([
            'user_id' => $this->owner->id,
            'access_token' => 'old',
            'refresh_token' => 'refresh-0',
            'expires_at' => now()->subHour(),
        ]);

        $this->assertSame('access-1', Freesound::accessToken($connection));
        $this->assertSame('refresh-1', $connection->fresh()->refresh_token);
    }

    public function test_track_tempo_and_meter_are_saved(): void
    {
        Sanctum::actingAs($this->owner);
        $genre = Genre::query()->firstOrCreate(['title' => 'Test genre'], ['slug' => 'test-genre']);
        $artist = Artist::factory()->create(['user_id' => $this->owner->id]);

        $this->putJson("/api/creator/projects/{$this->project->id}/tracks/{$this->track->id}", [
            'title' => 'Beat',
            'genre_id' => (string) $genre->id,
            'artist_id' => (string) $artist->id,
            'bpm' => 92.5,
            'time_signature' => '6/8',
        ])->assertAccepted()->assertJsonPath('data.bpm', 92.5)->assertJsonPath('data.timeSignature', '6/8');

        $this->putJson("/api/creator/projects/{$this->project->id}/tracks/{$this->track->id}", [
            'title' => 'Beat',
            'genre_id' => (string) $genre->id,
            'artist_id' => (string) $artist->id,
            'time_signature' => '9/9',
        ])->assertUnprocessable();
    }
}
