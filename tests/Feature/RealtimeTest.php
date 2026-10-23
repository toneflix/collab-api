<?php

namespace Tests\Feature;

use App\Broadcasting\Channels;
use App\Broadcasting\FirebaseBroadcaster;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\User;
use App\Services\Realtime\Realtime;
use App\Services\Realtime\RealtimeSettings;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Contract\Database;
use Laravel\Sanctum\Sanctum;
use Lcobucci\JWT\UnencryptedToken;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RealtimeTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, array{channels: array<int, string>, event: string, payload: array<string, mixed>}> */
    protected array $sent = [];

    protected User $owner;

    protected User $collaborator;

    protected User $stranger;

    protected ProjectTrack $track;

    protected function setUp(): void
    {
        parent::setUp();

        RealtimeSettings::flush();

        $this->owner = User::factory()->create();
        $this->collaborator = User::factory()->create();
        $this->stranger = User::factory()->create();

        $project = Project::query()->forceCreate([
            'user_id' => $this->owner->id,
            'title' => 'Realtime test',
            'privacy' => 'private',
        ]);

        $project->collaborators()->forceCreate(['user_id' => $this->collaborator->id, 'role' => 'vocals', 'accepted' => true]);

        $this->track = $project->tracks()->forceCreate(['title' => 'Realtime track', 'privacy' => 'private']);
        $this->track->stems()->forceCreate(['user_id' => $this->owner->id, 'meta' => ['name' => 'Drums']]);
    }

    protected function tearDown(): void
    {
        RealtimeSettings::flush();

        parent::tearDown();
    }

    /**
     * Use Reverb with made-up keys, and record what gets broadcast instead
     * of sending it.
     */
    protected function useSpyDriver(): void
    {
        RealtimeSettings::update([
            'realtime_driver' => 'reverb',
            'reverb_app_id' => 'app',
            'reverb_key' => 'key',
            'reverb_secret' => 'secret',
            'reverb_host' => 'localhost',
            'reverb_port' => '8080',
            'reverb_scheme' => 'http',
        ]);
        Realtime::configure();

        $spy = new class($this->sent) extends Broadcaster
        {
            public function __construct(public array &$sent) {}

            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = [])
            {
                $this->sent[] = ['channels' => array_map('strval', $channels), 'event' => $event, 'payload' => $payload];
            }
        };

        app(BroadcastManager::class)->extend('reverb', fn () => $spy);
        app(BroadcastManager::class)->forgetDrivers();
    }

    protected function admin(): User
    {
        Permission::findOrCreate('manage-configuration', 'web');
        $admin = User::factory()->create();
        $admin->givePermissionTo('manage-configuration');

        return $admin;
    }

    public function test_only_admins_can_see_realtime_settings(): void
    {
        Sanctum::actingAs($this->stranger);
        $this->getJson('/api/admin/realtime')->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->getJson('/api/admin/realtime')
            ->assertOk()
            ->assertJsonPath('data.drivers', RealtimeSettings::DRIVERS);
    }

    public function test_admins_save_settings_and_secrets_never_come_back(): void
    {
        Sanctum::actingAs($this->admin());

        $this->putJson('/api/admin/realtime', [
            'realtime_driver' => 'pusher',
            'pusher_app_id' => '123',
            'pusher_key' => 'public-key',
            'pusher_secret' => 'very-secret',
            'pusher_cluster' => 'eu',
        ])
            ->assertStatus(202)
            ->assertJsonPath('data.driver', 'pusher')
            ->assertJsonPath('data.fields.pusher_secret.value', null)
            ->assertJsonPath('data.fields.pusher_secret.isSet', true)
            ->assertJsonPath('data.client.pusher.key', 'public-key')
            ->assertJsonPath('data.missing', []);

        $this->assertStringNotContainsString('very-secret', (string) \DB::table('configurations')->where('key', 'pusher_secret')->value('value'));

        // An empty secret keeps the stored one.
        $this->putJson('/api/admin/realtime', ['realtime_driver' => 'pusher', 'pusher_secret' => ''])->assertStatus(202);
        $this->assertSame('very-secret', RealtimeSettings::get('pusher_secret'));
        $this->assertSame('pusher', config('broadcasting.default'));

        $this->getJson('/api/initialize')
            ->assertJsonPath('data.realtime.driver', 'pusher')
            ->assertJsonPath('data.realtime.pusher.cluster', 'eu')
            ->assertDontSee('very-secret');
    }

    public function test_settings_are_validated(): void
    {
        Sanctum::actingAs($this->admin());

        $this->putJson('/api/admin/realtime', ['realtime_driver' => 'carrier-pigeon'])->assertUnprocessable();
        $this->putJson('/api/admin/realtime', ['realtime_driver' => 'ably', 'ably_key' => 'no-secret'])->assertUnprocessable();
        $this->putJson('/api/admin/realtime', ['realtime_driver' => 'firebase', 'firebase_credentials' => '{not json'])->assertUnprocessable();
    }

    public function test_ably_uses_the_pusher_protocol_without_leaking_the_secret(): void
    {
        RealtimeSettings::update(['realtime_driver' => 'ably', 'ably_key' => 'app.key:shh']);

        $client = Realtime::clientConfig();

        $this->assertSame('pusher', $client['transport']);
        $this->assertSame('app.key', $client['pusher']['key']);
        $this->assertSame('realtime-pusher.ably.io', $client['pusher']['wsHost']);
        $this->assertStringNotContainsString('shh', json_encode($client));
    }

    public function test_channel_access(): void
    {
        RealtimeSettings::update([
            'realtime_driver' => 'reverb', 'reverb_app_id' => 'app', 'reverb_key' => 'key',
            'reverb_secret' => 'secret', 'reverb_host' => 'localhost',
        ]);
        Realtime::configure();

        $channel = 'private-project-track.'.$this->track->id;

        Sanctum::actingAs($this->collaborator);
        $this->postJson('/api/realtime/auth', ['channel_name' => $channel, 'socket_id' => '1.1'])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        Sanctum::actingAs($this->stranger);
        $this->postJson('/api/realtime/auth', ['channel_name' => $channel, 'socket_id' => '1.1'])->assertForbidden();
        $this->postJson('/api/realtime/auth', ['channel_name' => 'private-user.'.$this->owner->id, 'socket_id' => '1.1'])->assertForbidden();
        $this->postJson('/api/realtime/auth', ['channel_name' => 'private-user.'.$this->stranger->id, 'socket_id' => '1.1'])->assertOk();
    }

    public function test_auth_is_unavailable_when_realtime_is_off(): void
    {
        RealtimeSettings::update(['realtime_driver' => 'none']);

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/realtime/auth', ['channel_name' => 'private-user.'.$this->owner->id])->assertNotFound();
    }

    public function test_live_edits_reach_the_track_channel_with_the_senders_origin(): void
    {
        $this->useSpyDriver();
        $stem = $this->track->stems()->first();

        Sanctum::actingAs($this->collaborator);
        $this->postJson(
            "/api/realtime/projects/{$this->track->project_id}/tracks/{$this->track->id}/live",
            ['stems' => [$stem->id => ['gain' => 0.5, 'clips' => [['start' => 1]]], 999999 => ['gain' => 1]]],
            [Realtime::ORIGIN_HEADER => 'tab-abc']
        )->assertOk()->assertJsonPath('data.sent', true);

        $this->assertCount(1, $this->sent);
        $this->assertSame(['private-project-track.'.$this->track->id], $this->sent[0]['channels']);
        $this->assertSame('stems.updated', $this->sent[0]['event']);
        $this->assertSame('tab-abc', $this->sent[0]['payload']['origin']);
        $this->assertSame($this->collaborator->id, $this->sent[0]['payload']['by']['id']);
        $this->assertSame([(string) $stem->id], array_map('strval', array_keys($this->sent[0]['payload']['stems'])));
        $this->assertSame(0.5, $this->sent[0]['payload']['stems'][$stem->id]['gain']);
    }

    public function test_only_collaborators_can_send_live_edits(): void
    {
        $this->useSpyDriver();
        $stem = $this->track->stems()->first();

        Sanctum::actingAs($this->stranger);
        $this->postJson(
            "/api/realtime/projects/{$this->track->project_id}/tracks/{$this->track->id}/live",
            ['stems' => [$stem->id => ['gain' => 0.5]]]
        )->assertForbidden();

        $this->assertCount(0, $this->sent);
    }

    public function test_new_comments_are_broadcast_to_the_conversation(): void
    {
        $this->useSpyDriver();

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/projects/{$this->track->project_id}/comments?track_id={$this->track->id}", [
            'comment' => 'Love the bass',
        ])->assertCreated();

        $this->assertSame('comment.posted', $this->sent[0]['event']);
        $this->assertSame(['private-conversation.project-track.'.$this->track->id], $this->sent[0]['channels']);
        $this->assertSame('Love the bass', $this->sent[0]['payload']['comment']['comment']);
        $this->assertSame($this->owner->id, $this->sent[0]['payload']['comment']['user']['id']);
        $this->assertArrayNotHasKey('email', $this->sent[0]['payload']['comment']['user']);
    }

    public function test_a_broken_driver_never_fails_the_request(): void
    {
        $this->useSpyDriver();
        app(BroadcastManager::class)->extend('reverb', fn () => throw new \RuntimeException('down'));
        app(BroadcastManager::class)->forgetDrivers();

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/projects/{$this->track->project_id}/comments?track_id={$this->track->id}", [
            'comment' => 'Still works',
        ])->assertCreated();
    }

    public function test_firebase_writes_events_and_grants_in_the_arkstack_layout(): void
    {
        $writes = [];
        $firebase = new class(['credentials' => '{}', 'database_url' => 'https://x.firebaseio.com'], $writes) extends FirebaseBroadcaster
        {
            public function __construct(array $config, public array &$writes)
            {
                parent::__construct($config);
            }

            protected function write(array $updates): void
            {
                $this->writes[] = $updates;
            }
        };

        $firebase->broadcast(['private-project-track.7'], 'stems.updated', ['a' => 1, 'socket' => 'x']);

        $paths = array_keys($writes[0]);
        $this->assertMatchesRegularExpression('#^arkstack/channels/private-project-track%2E7/[0-9a-z]{26}$#', $paths[0]);
        $this->assertMatchesRegularExpression('#^arkstack/channels-index/[0-9a-z]{26}$#', $paths[1]);
        $this->assertSame(['event' => 'stems.updated', 'payload' => ['a' => 1], 'at' => ['.sv' => 'timestamp']], $writes[0][$paths[0]]);
        $this->assertSame('private-project-track%2E7', $writes[0][$paths[1]]['channel']);

        // Authorizing writes a grant and returns a custom token for the user.
        $token = $this->createMock(UnencryptedToken::class);
        $token->method('toString')->willReturn('custom-token');
        $auth = $this->createMock(Auth::class);
        $auth->expects($this->once())->method('createCustomToken')->with('user-'.$this->collaborator->id)->willReturn($token);
        $firebase->using($this->createMock(Database::class), $auth);

        Channels::register($firebase);
        $request = Request::create('/api/realtime/auth', 'POST', ['channel_name' => 'private-project-track.'.$this->track->id]);
        $request->setUserResolver(fn () => $this->collaborator);

        $response = $firebase->auth($request);

        $this->assertSame('custom-token', $response['token']);
        $this->assertArrayHasKey('arkstack/grants/user-'.$this->collaborator->id.'/private-project-track%2E'.$this->track->id, $writes[1]);
    }
}
