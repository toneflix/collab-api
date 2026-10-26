<?php

namespace Tests\Feature;

use App\Enums\NotificationCategory;
use App\Models\DeviceToken;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Notifications\Channels\FcmChannel;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class AppPushTest extends TestCase
{
    use DatabaseTransactions;

    protected User $owner;

    protected User $requester;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.frontend_url' => 'https://app.test',
            'services.firebase.credentials' => '{"type":"service_account"}',
            'webpush.vapid.public_key' => null,
            'webpush.vapid.private_key' => null,
        ]);

        $this->owner = User::factory()
            ->create(['firstname' => 'Ada']);
        $this->requester = User::factory()
            ->create(['firstname' => 'Ben']);
    }

    public function test_an_app_token_can_be_registered_moved_and_removed(): void
    {
        Sanctum::actingAs($this->owner);

        $this
            ->postJson('/api/account/device-tokens', ['token' => 'token-1', 'platform' => 'android'])
            ->assertCreated()
            ->assertJsonPath('data.push.nativeEnabled', true)
            ->assertJsonPath('data.push.devices', 1);

        Sanctum::actingAs($this->requester);

        $this
            ->postJson('/api/account/device-tokens', ['token' => 'token-1', 'platform' => 'android'])
            ->assertCreated();

        $this->assertSame($this->requester->id, DeviceToken::query()->firstWhere('token', 'token-1')->user_id);

        $this
            ->deleteJson('/api/account/device-tokens', ['token' => 'token-1'])
            ->assertAccepted()
            ->assertJsonPath('data.push.devices', 0);
    }

    public function test_tokens_are_refused_without_firebase_credentials(): void
    {
        config([
            'services.firebase.credentials' => null,
            'broadcasting.connections.firebase.credentials' => null,
        ]);
        Sanctum::actingAs($this->owner);

        $this
            ->postJson('/api/account/device-tokens', ['token' => 'token-1', 'platform' => 'android'])
            ->assertUnprocessable();
    }

    public function test_notifications_go_to_registered_apps_when_push_is_on(): void
    {
        $prefs = NotificationPreferences::for($this->owner);

        $this->assertNotContains(FcmChannel::class, $prefs->channelsFor(NotificationCategory::COLLABS));

        $this->owner
            ->deviceTokens()
            ->create(['token' => 'token-1', 'platform' => 'android']);

        $this->assertContains(FcmChannel::class, $prefs->channelsFor(NotificationCategory::COLLABS));

        $prefs->update(['collabs' => ['push' => false]]);

        $this->assertNotContains(FcmChannel::class, NotificationPreferences::for($this->owner->fresh())->channelsFor(NotificationCategory::COLLABS));
    }

    public function test_the_app_message_shows_in_the_category_channel_and_opens_the_page(): void
    {
        $message = $this
            ->notification()
            ->toFcm($this->owner)
            ->jsonSerialize();

        $this->assertSame('collabs', $message['android']['notification']['channel_id']);
        $this->assertSame('high', $message['android']['priority']);
        $this->assertSame('/notifications?tab=requests', $message['data']['path']);
        $this->assertSame('https://app.test' . $message['data']['path'], $message['data']['url']);
        $this->assertNotEmpty($message['notification']['title']);
    }

    public function test_the_channel_sends_to_every_app_and_drops_unknown_tokens(): void
    {
        $this->owner
            ->deviceTokens()
            ->createMany([
                ['token' => 'live-token', 'platform' => 'android'],
                ['token' => 'gone-token', 'platform' => 'android'],
            ]);

        $report = MulticastSendReport::withItems([
            SendReport::success(MessageTarget::with(MessageTarget::TOKEN, 'live-token'), []),
            SendReport::failure(MessageTarget::with(MessageTarget::TOKEN, 'gone-token'), new NotFound('Requested entity was not found.')),
        ]);

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->with(Mockery::type(CloudMessage::class), ['live-token', 'gone-token'])
            ->andReturn($report);
        $this->app->instance(Messaging::class, $messaging);

        (new FcmChannel)->send($this->owner, $this->notification());

        $tokens = $this->owner
            ->deviceTokens()
            ->pluck('token')
            ->all();

        $this->assertSame(['live-token'], $tokens);
    }

    protected function notification(): ActivityNotification
    {
        return new class extends ActivityNotification
        {
            public function category(): NotificationCategory
            {
                return NotificationCategory::COLLABS;
            }

            protected function key(): string
            {
                return 'collabs.request';
            }

            protected function params(User $notifiable): array
            {
                return ['sendername' => 'Ben', 'title' => 'Night Drive', 'role' => 'producer'];
            }

            protected function path(): string
            {
                return '/notifications?tab=requests';
            }
        };
    }
}
