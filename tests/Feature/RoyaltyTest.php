<?php

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\Configuration;
use App\Models\RoyaltyContract;
use App\Models\RoyaltyInvite;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\RoyaltyInviteNotification;
use App\Services\Referrals\Referrals;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RoyaltyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();
        Permission::findOrCreate('manage-payouts', 'web');

        Configuration::where('key', 'loyalty_rate')
            ->update(['value' => 10]);
        Cache::forget('configuration::build');
    }

    protected function actAs(?User $user): void
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->controller = null;
        }

        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        if ($user) {
            Sanctum::actingAs($user->fresh());
        }
    }

    protected function admin(): User
    {
        $admin = User::factory()
            ->create();
        $admin->givePermissionTo('manage-payouts');

        $this->actAs($admin);

        return $admin;
    }

    protected function inviteNew(string $email, array $extra = []): RoyaltyInvite
    {
        $this
            ->postJson('/api/admin/royalties/invites', [
                'email' => $email,
                'name' => 'Royal Partner',
                'rate' => 35,
                'months' => 0,
                ...$extra,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        return RoyaltyInvite::query()
            ->where('email', strtolower($email))
            ->firstOrFail();
    }

    public function test_only_payout_managers_can_invite(): void
    {
        $this->actAs(User::factory()->create());

        $this
            ->postJson('/api/admin/royalties/invites', ['email' => 'x@example.com', 'name' => 'X', 'rate' => 20])
            ->assertForbidden();
    }

    public function test_a_new_person_signs_up_from_the_invite_and_earns_their_rate(): void
    {
        $this->admin();

        $email = 'Royal-' . uniqid() . '@Example.com';
        $code = 'royal' . substr(uniqid(), -6);
        $invite = $this->inviteNew($email, ['code' => $code]);

        Notification::assertSentTo(
            new AnonymousNotifiable,
            RoyaltyInviteNotification::class,
            fn($notification, $channels, $notifiable) => $notifiable->routes['mail'] === strtolower($email),
        );

        // Nobody else can take the reserved code meanwhile.
        $this->actAs(User::factory()->create());
        $this
            ->putJson('/api/account/referrals', ['code' => $code])
            ->assertUnprocessable();

        $this->actAs(null);

        $this
            ->getJson("/api/royalty-invites/{$invite->token}")
            ->assertOk()
            ->assertJsonPath('data.rate', 35)
            ->assertJsonMissingPath('data.note');

        $body = [
            'firstname' => 'Royal',
            'lastname' => 'Partner',
            'email' => 'someone-else-' . uniqid() . '@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'account_type' => 'creator',
            'identities' => ['artist'],
            'accept' => true,
            'royalty_token' => $invite->token,
        ];

        $this
            ->postJson('/api/auth/register', $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('royalty_token');

        $this
            ->postJson('/api/auth/register', [...$body, 'email' => $email])
            ->assertCreated();

        $royal = User::query()
            ->where('email', $email)
            ->firstOrFail();

        $this->assertSame($code, $royal->referral_code);
        $this->assertNotNull($invite->fresh()->accepted_at);

        $contract = $royal->currentRoyaltyContract();

        $this->assertEquals(35, $contract->rate);
        $this->assertTrue(AdminAction::where('user_id', $royal->id)->where('action', 'royalty.accepted')->exists());

        // Their invitee's premium payment of 2000 earns them 35%.
        $invitee = User::factory()
            ->create();
        $invitee->forceFill(['referred_by_id' => $royal->id, 'referred_at' => now()->subYears(3)])->save();

        $payment = $this->payment($invitee, 2000);
        $reward = app(Referrals::class)->reward($payment);

        $this->assertEquals(700, $reward->recipient_share);
        $this->assertSame('royalty', $reward->method);
        $this->assertSame($contract->id, $reward->meta['contract_id']);
    }

    public function test_an_existing_user_accepts_in_the_app(): void
    {
        $admin = $this->admin();
        $user = User::factory()
            ->create();
        $invite = $this->inviteNew($user->email);

        Notification::assertSentTo($user, RoyaltyInviteNotification::class);
        $this->assertSame($user->id, $invite->user_id);

        // Someone else signed in can't take it.
        $this->actAs(User::factory()->create());
        $this
            ->postJson("/api/royalty-invites/{$invite->token}/accept")
            ->assertUnprocessable();

        $this->actAs($user);
        $this
            ->postJson("/api/royalty-invites/{$invite->token}/accept")
            ->assertCreated()
            ->assertJsonPath('data.rate', 35);

        $this
            ->postJson("/api/royalty-invites/{$invite->token}/accept")
            ->assertUnprocessable();

        $this
            ->getJson('/api/account/referrals')
            ->assertJsonPath('data.terms.program', 'royalty')
            ->assertJsonPath('data.terms.rate', 35);

        // Inviting a current royal again is refused.
        $this->actAs($admin);
        $this
            ->postJson('/api/admin/royalties/invites', ['email' => $user->email, 'name' => 'Again', 'rate' => 20])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_changing_terms_starts_a_new_contract_and_ending_falls_back_to_loyalty(): void
    {
        $admin = $this->admin();
        $royal = User::factory()
            ->create();
        $contract = RoyaltyContract::query()
            ->create(['user_id' => $royal->id, 'rate' => 30, 'months' => 0, 'starts_at' => now()->subMonth()]);

        $invitee = User::factory()
            ->create();
        $invitee->forceFill(['referred_by_id' => $royal->id, 'referred_at' => now()->subDay()])->save();
        app(Referrals::class)->reward($this->payment($invitee, 1000));

        $this
            ->getJson('/api/admin/royalties/contracts?status=active')
            ->assertOk()
            ->assertJsonPath('data.0.id', $contract->id)
            ->assertJsonPath('data.0.invited', 1)
            ->assertJsonPath('data.0.earned', 300);

        $newId = $this
            ->putJson("/api/admin/royalties/contracts/{$contract->id}", ['rate' => 40])
            ->assertAccepted()
            ->assertJsonPath('data.rate', 40)
            ->json('data.id');

        $this->assertNotSame($contract->id, $newId);
        $this->assertNotNull($contract->fresh()->ends_at);
        $this->assertEquals(40, app(Referrals::class)->terms($royal)->rate);

        $this
            ->postJson("/api/admin/royalties/contracts/{$newId}/end", ['reason' => 'Partnership over'])
            ->assertAccepted()
            ->assertJsonPath('data.active', false);

        $this->assertSame('loyalty', app(Referrals::class)->terms($royal->fresh())->program);
        $this->assertTrue(AdminAction::where('user_id', $royal->id)->where('action', 'royalty.ended')->where('admin_id', $admin->id)->exists());
    }

    public function test_invites_can_be_resent_and_cancelled(): void
    {
        $this->admin();

        $invite = $this->inviteNew('cancel-' . uniqid() . '@example.com');
        $invite->forceFill(['expires_at' => now()->subDay()])->save();

        $this
            ->getJson('/api/admin/royalties/invites?status=expired')
            ->assertJsonPath('data.0.id', $invite->id);

        $this
            ->postJson("/api/admin/royalties/invites/{$invite->id}/resend")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending');

        $this
            ->postJson("/api/admin/royalties/invites/{$invite->id}/cancel")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'cancelled');

        $this->actAs(null);

        $this
            ->postJson('/api/auth/register', [
                'firstname' => 'Late',
                'email' => $invite->email,
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'account_type' => 'listener',
                'accept' => true,
                'royalty_token' => $invite->token,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('royalty_token');
    }

    protected function payment(User $payer, float $amount): Transaction
    {
        $payment = new Transaction([
            'reference' => 'test-' . uniqid(),
            'purpose' => 'premium',
            'method' => 'paystack',
            'status' => 'complete',
            'amount' => $amount,
            'due' => $amount,
            'paid_at' => now(),
        ]);
        $payment->user_id = $payer->id;
        $payment
            ->transactable()
            ->associate($payer);
        $payment->save();

        return $payment;
    }
}
