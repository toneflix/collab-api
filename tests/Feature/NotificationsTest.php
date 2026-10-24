<?php

namespace Tests\Feature;

use App\Enums\NotificationCategory;
use App\Jobs\NotifyUnreadMessages;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Notifications\Channels\PushChannel;
use App\Notifications\JoinProjectRequestNotification;
use App\Notifications\NewMessagesNotification;
use App\Notifications\SaleNotification;
use App\Services\Conversations\Conversations;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Payment\Checkout;
use App\Services\Payment\PaymentGateway;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use DatabaseTransactions;

    protected User $owner;

    protected User $collaborator;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.frontend_url' => 'https://app.test',
            'webpush.vapid.public_key' => 'BPublicKeyForTests',
            'webpush.vapid.private_key' => 'private-key-for-tests',
        ]);

        $this->owner = User::factory()->create(['firstname' => 'Ada']);
        $this->collaborator = User::factory()->create(['firstname' => 'Ben']);

        $this->project = Project::query()->forceCreate([
            'user_id' => $this->owner->id,
            'title' => 'Night Drive',
            'privacy' => 'private',
        ]);
    }

    /**
     * Switch user between requests. Controllers keep the user they were built with.
     */
    protected function actingAsFresh(User $user): User
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->controller = null;
        }

        $user = $user->fresh();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_preferences_default_to_on_and_can_be_changed(): void
    {
        $this->actingAsFresh($this->owner);

        $this->getJson('/api/account/notification-settings')
            ->assertOk()
            ->assertJsonPath('data.categories.0.key', 'collabs')
            ->assertJsonPath('data.categories.0.mail', true)
            ->assertJsonPath('data.categories.0.push', true)
            ->assertJsonPath('data.push.enabled', true)
            ->assertJsonPath('data.push.publicKey', 'BPublicKeyForTests');

        $this->putJson('/api/account/notification-settings', [
            'categories' => ['messages' => ['mail' => false], 'sales' => ['push' => false], 'unknown' => ['mail' => false]],
        ])->assertAccepted();

        $prefs = NotificationPreferences::for($this->owner->fresh());
        $this->assertFalse($prefs->allows(NotificationCategory::MESSAGES, 'mail'));
        $this->assertTrue($prefs->allows(NotificationCategory::MESSAGES, 'push'));
        $this->assertFalse($prefs->allows(NotificationCategory::SALES, 'push'));
        $this->assertTrue($prefs->allows(NotificationCategory::COLLABS, 'mail'));
    }

    public function test_the_old_email_switch_turns_email_off(): void
    {
        $this->owner->data = ['settings' => ['notifications' => ['email' => false]]];
        $this->owner->save();

        $prefs = NotificationPreferences::for($this->owner->fresh());

        $this->assertFalse($prefs->allows(NotificationCategory::COLLABS, 'mail'));
        $this->assertTrue($prefs->allows(NotificationCategory::COLLABS, 'push'));
    }

    public function test_channels_follow_preferences_and_push_devices(): void
    {
        $prefs = NotificationPreferences::for($this->owner);
        $this->assertSame(['database', 'mail'], $prefs->channelsFor(NotificationCategory::SALES));

        $this->owner->updatePushSubscription('https://push.test/abc', 'key', 'auth');
        $this->assertSame(['database', 'mail', PushChannel::class], $prefs->channelsFor(NotificationCategory::SALES));

        $prefs->update(['sales' => ['mail' => false, 'push' => false]]);
        $this->assertSame(['database'], NotificationPreferences::for($this->owner->fresh())->channelsFor(NotificationCategory::SALES));

        config(['webpush.vapid.private_key' => null]);
        $this->assertNotContains(PushChannel::class, NotificationPreferences::for($this->owner->fresh())->channelsFor(NotificationCategory::COLLABS));
    }

    public function test_an_invitation_notifies_the_invited_user(): void
    {
        Notification::fake();
        $this->actingAsFresh($this->owner);

        $this->postJson("/api/creator/projects/{$this->project->id}/collaborators", [
            'user_id' => (string) $this->collaborator->id,
            'role' => 'vocals',
        ])->assertCreated();

        Notification::assertSentTo($this->collaborator, JoinProjectRequestNotification::class, function ($n, $channels) {
            $content = $n->content($this->collaborator);

            return in_array('mail', $channels)
                && $content['subject'] === "{$this->owner->fullname} invited you to Night Drive"
                && $content['url'] === 'https://app.test/notifications?tab=requests';
        });
        Notification::assertNotSentTo($this->owner, JoinProjectRequestNotification::class);
    }

    public function test_a_join_request_notifies_the_project_owner(): void
    {
        Notification::fake();
        $this->project->update(['privacy' => 'public']);
        $this->actingAsFresh($this->collaborator);

        $this->postJson("/api/projects/{$this->project->id}/collaborators", ['role' => 'vocals'])->assertCreated();

        Notification::assertSentTo($this->owner, JoinProjectRequestNotification::class, function ($n) {
            return str_contains($n->content($this->owner)['body'], 'asked to join your project "Night Drive" as vocals');
        });
        Notification::assertNotSentTo($this->collaborator, JoinProjectRequestNotification::class);
    }

    public function test_a_sale_notifies_the_creator_with_their_share(): void
    {
        Notification::fake();
        config(['app.frontend_url' => 'https://app.test']);

        $transaction = new Transaction([
            'reference' => 'TRX-'.uniqid(),
            'purpose' => 'tip',
            'method' => 'paystack',
            'status' => 'pending',
            'amount' => 1000,
            'due' => 1000,
            'discount' => 0,
            'fees' => 0,
            'recipient_id' => $this->owner->id,
            'meta' => ['message' => 'Love the record'],
        ]);
        $transaction->user_id = $this->collaborator->id;
        $transaction->transactable()->associate($this->owner);
        $transaction->save();

        (new Checkout(Mockery::mock(PaymentGateway::class)))->settle($transaction, true, 1000);

        Notification::assertSentTo($this->owner, SaleNotification::class, function (SaleNotification $n) {
            $content = $n->content($this->owner);

            return $content['subject'] === "{$this->collaborator->fullname} sent you a tip"
                && str_contains($content['body'], '"Love the record"')
                && $content['url'] === 'https://app.test/studio/earnings';
        });
        Notification::assertNotSentTo($this->collaborator, SaleNotification::class);
    }

    public function test_a_failed_payment_notifies_nobody(): void
    {
        Notification::fake();

        $transaction = new Transaction([
            'reference' => 'TRX-'.uniqid(),
            'purpose' => 'tip',
            'method' => 'paystack',
            'status' => 'pending',
            'amount' => 1000,
            'due' => 1000,
            'recipient_id' => $this->owner->id,
        ]);
        $transaction->user_id = $this->collaborator->id;
        $transaction->transactable()->associate($this->owner);
        $transaction->save();

        (new Checkout(Mockery::mock(PaymentGateway::class)))->settle($transaction, false, 0);

        Notification::assertNothingSent();
    }

    public function test_a_message_queues_one_delayed_notification_per_member(): void
    {
        Queue::fake();
        $this->project->collaborators()->forceCreate(['user_id' => $this->collaborator->id, 'role' => 'vocals', 'accepted' => true]);
        $track = $this->project->tracks()->forceCreate(['title' => 'Hook', 'privacy' => 'private']);
        $this->actingAsFresh($this->collaborator);

        $this->postJson("/api/conversations/project-track/{$track->id}/messages", ['body' => 'New take is up'])->assertSuccessful();

        Queue::assertPushed(NotifyUnreadMessages::class, 1);
        Queue::assertPushed(NotifyUnreadMessages::class, fn (NotifyUnreadMessages $job) => $job->userId === $this->owner->id
            && $job->rootType === ProjectTrack::class
            && $job->delay !== null);
    }

    public function test_unread_messages_are_summed_up_and_read_ones_are_skipped(): void
    {
        Notification::fake();
        $this->project->collaborators()->forceCreate(['user_id' => $this->collaborator->id, 'role' => 'vocals', 'accepted' => true]);
        $track = $this->project->tracks()->forceCreate(['title' => 'Hook', 'privacy' => 'private']);

        $track->commentAsUser($this->collaborator, 'First idea');
        $track->commentAsUser($this->collaborator, 'Second idea');

        (new NotifyUnreadMessages($this->owner->id, ProjectTrack::class, $track->id))->handle();

        Notification::assertSentTo($this->owner, NewMessagesNotification::class, function (NewMessagesNotification $n) use ($track) {
            $content = $n->content($this->owner);

            return $content['subject'] === '2 new messages in Hook'
                && $content['url'] === "https://app.test/messages/project-track/{$track->id}";
        });

        Notification::fake();
        Conversations::markRead($track, $this->owner);
        (new NotifyUnreadMessages($this->owner->id, ProjectTrack::class, $track->id))->handle();

        Notification::assertNothingSent();
    }

    public function test_a_conversation_notifies_a_user_at_most_once_per_delay(): void
    {
        Notification::fake();
        $this->project->collaborators()->forceCreate(['user_id' => $this->collaborator->id, 'role' => 'vocals', 'accepted' => true]);
        $track = $this->project->tracks()->forceCreate(['title' => 'Hook', 'privacy' => 'private']);

        $track->commentAsUser($this->collaborator, 'One');
        (new NotifyUnreadMessages($this->owner->id, ProjectTrack::class, $track->id))->handle();
        $track->commentAsUser($this->collaborator, 'Two');
        (new NotifyUnreadMessages($this->owner->id, ProjectTrack::class, $track->id))->handle();

        Notification::assertSentToTimes($this->owner, NewMessagesNotification::class, 1);
    }

    public function test_people_who_cant_see_a_conversation_are_not_notified(): void
    {
        Notification::fake();
        $stranger = User::factory()->create();
        $track = $this->project->tracks()->forceCreate(['title' => 'Hook', 'privacy' => 'private']);
        $track->commentAsUser($this->owner, 'Secret');

        (new NotifyUnreadMessages($stranger->id, ProjectTrack::class, $track->id))->handle();

        Notification::assertNothingSent();
    }

    public function test_notifications_are_listed_and_marked_read(): void
    {
        $this->owner->notify(new NewMessagesNotification('project', $this->project->id, 'Night Drive', 1, ['Ben'], 'Hi'));
        $this->actingAsFresh($this->owner);

        $id = $this->getJson('/api/account/notifications?withStats=1')
            ->assertOk()
            ->assertJsonPath('stats.unread', 1)
            ->assertJsonPath('data.0.title', 'New message in Night Drive')
            ->assertJsonPath('data.0.message', 'Ben: Hi')
            ->assertJsonPath('data.0.category', 'messages')
            ->assertJsonPath('data.0.path', "/messages/project/{$this->project->id}")
            ->json('data.0.id');

        $this->patchJson("/api/account/notifications/{$id}")->assertAccepted()->assertJsonPath('stats.unread', 0);

        $this->owner->notify(new NewMessagesNotification('project', $this->project->id, 'Night Drive', 3, ['Ben', 'Cy', 'Di'], 'Hi'));
        $this->postJson('/api/account/notifications/read')->assertAccepted()->assertJsonPath('stats.unread', 0);
    }

    public function test_push_subscriptions_can_be_added_and_removed(): void
    {
        $this->actingAsFresh($this->owner);

        $this->postJson('/api/account/push-subscriptions', [
            'endpoint' => 'https://push.test/device-1',
            'keys' => ['p256dh' => 'public', 'auth' => 'secret'],
        ])->assertCreated()->assertJsonPath('data.push.devices', 1);

        $this->deleteJson('/api/account/push-subscriptions', ['endpoint' => 'https://push.test/device-1'])
            ->assertAccepted()
            ->assertJsonPath('data.push.devices', 0);
    }

    public function test_push_message_links_to_the_app(): void
    {
        $push = (new SaleNotification($this->tipFrom($this->collaborator)))->toWebPush($this->owner)->toArray();

        $this->assertSame("{$this->collaborator->fullname} sent you a tip", $push['title']);
        $this->assertSame('https://app.test/studio/earnings', $push['data']['url']);
        $this->assertSame('https://app.test/icons/favicon-128x128.png', $push['icon']);
    }

    public function test_emails_render_with_a_working_unsubscribe_link(): void
    {
        $mail = (new SaleNotification($this->tipFrom($this->collaborator)))->toMail($this->owner);
        $html = (string) $mail->render();

        $this->assertStringContainsString('See earnings', $html);
        $this->assertStringContainsString('Turn them off', $html);

        $url = ActivityNotification::unsubscribeUrl($this->owner, NotificationCategory::SALES);

        $this->get($url)->assertRedirect('https://app.test/account/notifications?unsubscribed=sales');
        $this->assertFalse(NotificationPreferences::for($this->owner->fresh())->allows(NotificationCategory::SALES, 'mail'));
        $this->assertTrue(NotificationPreferences::for($this->owner->fresh())->allows(NotificationCategory::COLLABS, 'mail'));
    }

    public function test_unsigned_unsubscribe_links_are_refused(): void
    {
        $this->get("/api/notifications/unsubscribe/{$this->owner->id}/sales")->assertForbidden();
        $this->post(URL::signedRoute('notifications.unsubscribe', ['user' => $this->owner->id, 'category' => 'nope']))->assertNotFound();
    }

    protected function tipFrom(User $payer): Transaction
    {
        $transaction = new Transaction([
            'purpose' => 'tip',
            'amount' => 500,
            'recipient_share' => 400,
            'recipient_id' => $this->owner->id,
        ]);
        $transaction->user_id = $payer->id;
        $transaction->setRelation('user', $payer);

        return $transaction;
    }
}
