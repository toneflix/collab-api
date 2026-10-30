<?php

namespace Tests\Feature;

use App\Models\Configuration;
use App\Models\Plan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payment\Checkout;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\Payouts;
use App\Services\Referrals\Referrals;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReferralTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $gateway = new class implements PaymentGateway
        {
            public array $initialized = [];

            public array $currencies = [];

            public function key(): string
            {
                return 'paystack';
            }

            public function acceptsPayments(): bool
            {
                return true;
            }

            public function initialize(PaymentRequest $request): array
            {
                $reference = $request->reference;
                $this->initialized[$reference] = $request->amount;
                $this->currencies[$reference] = $request->currency;

                return ['reference' => $reference, 'access_code' => 'code_' . $reference, 'authorization_url' => 'https://checkout.test/' . $reference];
            }

            public function verify(string $reference): array
            {
                return ['final' => true, 'paid' => true, 'amount' => $this->initialized[$reference] ?? 0, 'currency' => $this->currencies[$reference] ?? null, 'raw' => []];
            }

            public function validWebhook(string $payload, ?string $signature): bool
            {
                return false;
            }
        };

        $this->app->instance(PaymentGateway::class, $gateway);

        Plan::where('slug', 'premium')
            ->update(['price' => 2000, 'interval' => 'month', 'active' => true]);

        $this->setting('creator_share', 80);
        $this->setting('min_tip_amount', 100);
        $this->setting('loyalty_rate', 10);
        $this->setting('loyalty_months', 12);
    }

    protected function setting(string $key, mixed $value): void
    {
        Configuration::where('key', $key)
            ->update(['value' => $value]);
        Cache::forget('configuration::build');
    }

    protected function register(array $extra = []): User
    {
        $email = 'invitee-' . uniqid() . '@example.com';

        $this
            ->postJson('/api/auth/register', [
                'firstname' => 'Ada',
                'lastname' => 'Okafor',
                'email' => $email,
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'account_type' => 'listener',
                'accept' => true,
                ...$extra,
            ])
            ->assertCreated();

        return User::query()
            ->where('email', $email)
            ->firstOrFail();
    }

    /**
     * Switch user between requests. Controllers keep the user they were built with, so drop them.
     */
    protected function actAs(?User $user): void
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->controller = null;
        }

        $this->app['auth']->forgetGuards();

        if ($user) {
            Sanctum::actingAs($user);
        }
    }

    protected function pay(User $payer, array $body): Transaction
    {
        $this->actAs($payer);

        $reference = $this
            ->postJson('/api/payments', $body)
            ->assertCreated()
            ->json('data.reference');

        $this
            ->postJson("/api/payments/{$reference}/verify")
            ->assertOk();

        return Transaction::where('reference', $reference)
            ->firstOrFail();
    }

    protected function invited(User $referrer): User
    {
        $invitee = User::factory()
            ->create();
        $invitee->forceFill(['referred_by_id' => $referrer->id, 'referred_at' => now()])->save();

        return $invitee;
    }

    public function test_a_code_is_made_from_the_username_and_can_be_changed(): void
    {
        $user = User::factory()
            ->create(['username' => 'ada_obi' . uniqid()]);

        Sanctum::actingAs($user);

        $this
            ->getJson('/api/account/referrals')
            ->assertOk()
            ->assertJsonPath('data.code', strtolower($user->username))
            ->assertJsonPath('data.terms.rate', 10)
            ->assertJsonPath('data.invited', 0);

        $code = 'ADA-' . substr(uniqid(), -6);

        $this
            ->putJson('/api/account/referrals', ['code' => $code])
            ->assertAccepted()
            ->assertJsonPath('data.code', strtolower($code));

        $other = User::factory()
            ->create();

        $this->actAs($other);

        $this
            ->putJson('/api/account/referrals', ['code' => $code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this
            ->putJson('/api/account/referrals', ['code' => 'no spaces'])
            ->assertUnprocessable();
    }

    public function test_signing_up_with_a_code_records_the_inviter(): void
    {
        $referrer = User::factory()
            ->create();
        $referrer->forceFill(['referral_code' => 'friend' . substr(uniqid(), -6)])->save();

        $this
            ->getJson('/api/referrals/' . strtoupper($referrer->referral_code))
            ->assertOk()
            ->assertJsonPath('data.username', $referrer->username);

        $this
            ->getJson('/api/referrals/nobody-has-this')
            ->assertNotFound();

        $this
            ->postJson('/api/auth/register', [
                'firstname' => 'Bad',
                'email' => 'bad-' . uniqid() . '@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'account_type' => 'listener',
                'accept' => true,
                'referral_code' => 'nobody-has-this',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('referral_code');

        $invitee = $this->register(['referral_code' => $referrer->referral_code]);

        $this->assertSame($referrer->id, $invitee->referred_by_id);
        $this->assertNotNull($invitee->referred_at);
    }

    public function test_the_inviter_earns_part_of_the_platform_share(): void
    {
        $referrer = User::factory()
            ->create();
        $creator = User::factory()
            ->create();
        $invitee = $this->invited($referrer);

        // A tip of 1000 leaves 200 with the platform; 10% of that is 20.
        $tip = $this->pay($invitee, ['purpose' => 'tip', 'id' => $creator->username, 'amount' => 1000]);
        // Premium of 2000 is all platform share; 10% is 200.
        $this->pay($invitee, ['purpose' => 'premium', 'plan' => 'premium']);

        $rewards = Transaction::query()
            ->where('recipient_id', $referrer->id)
            ->where('purpose', 'referral')
            ->orderBy('id')
            ->get();

        $this->assertEquals([20, 200], $rewards->pluck('recipient_share')->all());
        $this->assertTrue($rewards->first()->transactable->is($tip));
        $this->assertNull($rewards->first()->user_id);

        // Settling the same payment again pays nothing more.
        app(Checkout::class)
            ->settle($tip, true, 1000);
        app(Referrals::class)
            ->reward($tip->fresh());

        $this->assertSame(2, Transaction::where('recipient_id', $referrer->id)->where('purpose', 'referral')->count());

        // Earnings count toward the balance like any other.
        $this->assertEquals(220, app(Payouts::class)->summary($referrer)['earned']);

        $this->actAs($referrer);

        $this
            ->getJson('/api/account/referrals/invites')
            ->assertOk()
            ->assertJsonPath('data.0.username', $invitee->username)
            ->assertJsonPath('data.0.earned', 220)
            ->assertJsonPath('meta.total', 1);

        $this
            ->getJson('/api/account/referrals')
            ->assertJsonPath('data.invited', 1)
            ->assertJsonPath('data.earned', 220);

        $this
            ->getJson('/api/account/earnings?purpose=referral')
            ->assertOk()
            ->assertJsonPath('data.0.item.type', 'invite')
            ->assertJsonPath('data.0.item.username', $invitee->username);

        // The invitee's purchases don't list the reward.
        $this->actAs($invitee);

        $this
            ->getJson('/api/account/purchases')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_payments_after_the_reward_window_earn_nothing(): void
    {
        $referrer = User::factory()
            ->create();
        $invitee = $this->invited($referrer);
        $invitee->forceFill(['referred_at' => now()->subMonths(13)])->save();

        $this->pay($invitee, ['purpose' => 'premium', 'plan' => 'premium']);

        $this->assertSame(0, Transaction::where('recipient_id', $referrer->id)->count());

        $this->setting('loyalty_months', 0);

        $this->pay($invitee, ['purpose' => 'premium', 'plan' => 'premium']);

        $this->assertSame(1, Transaction::where('recipient_id', $referrer->id)->count());
    }
}
