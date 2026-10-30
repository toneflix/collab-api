<?php

namespace Tests\Feature;

use App\Enums\PayoutStatus;
use App\Models\Configuration;
use App\Models\Payout;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\PayoutNotification;
use App\Services\Payment\CollectsBankDetails;
use App\Services\Payment\HostsPayoutAccounts;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PayoutGateway;
use App\Services\Payment\PayoutMethod;
use App\Services\Payment\PayoutProviders;
use App\Services\Payment\PayoutSettings;
use App\Services\Payment\PayoutTransfer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A payout provider that pays bank accounts, answering the way the tests set it up.
 */
class FakePayoutGateway implements CollectsBankDetails, PayoutGateway
{
    /** What the next transfer call answers with, or 'refuse' to throw */
    public string $transferStatus = 'processing';

    /** @var array<string, string> reference => status verifyTransfer answers with */
    public array $verified = [];

    /** @var array<int, PayoutTransfer> */
    public array $sent = [];

    public int $recipients = 0;

    /**
     * @param  array<string, string>  $countries  code => currency
     */
    public function __construct(
        protected string $key = 'paystack',
        protected array $countries = ['NG' => 'NGN'],
        public float $exchangeRate = 1.0,
        protected string $lookup = PayoutMethod::LOOKUP_RESOLVE,
    ) {}

    /**
     * @return array<int, string>
     */
    public function transfers(): array
    {
        return array_map(fn(PayoutTransfer $t) => $t->reference, $this->sent);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function countries(): array
    {
        return $this->countries;
    }

    public function currencyFor(string $country): string
    {
        return $this->countries[$country] ?? '';
    }

    public function methods(string $country): array
    {
        return [new PayoutMethod('bank', 'Bank account', 'nuban', $this->lookup, numberPattern: '^\d{10}$')];
    }

    public function rate(string $from, string $to): float
    {
        return $from === $to ? 1.0 : $this->exchangeRate;
    }

    public function banks(string $country, PayoutMethod $method): array
    {
        return [
            ['name' => 'Access Bank', 'code' => '044'],
            ['name' => 'GTBank', 'code' => '058'],
        ];
    }

    public function lookup(string $country, PayoutMethod $method, string $bankCode, string $accountNumber, array $details = []): string
    {
        if ($method->lookup !== PayoutMethod::LOOKUP_RESOLVE) {
            return (string) ($details['account_name'] ?? '');
        }

        if ($accountNumber === '0000000000') {
            throw ValidationException::withMessages(['account_number' => 'Could not resolve account name.']);
        }

        return 'ADA LOVELACE';
    }

    public function createRecipient(string $country, PayoutMethod $method, string $name, string $bankCode, string $accountNumber): string
    {
        return 'RCP_' . (++$this->recipients);
    }

    public function transfer(PayoutTransfer $transfer): array
    {
        if ($this->transferStatus === 'refuse') {
            throw ValidationException::withMessages(['payout' => 'Your balance is not enough to fulfil this request']);
        }

        $this->sent[] = $transfer;

        return ['status' => $this->transferStatus, 'transfer_code' => 'TRF_' . $transfer->reference, 'reason' => null, 'raw' => ['reference' => $transfer->reference]];
    }

    public function verifyTransfer(string $reference, ?string $transferCode = null): array
    {
        return [
            'status' => $this->verified[$reference] ?? 'processing',
            'transfer_code' => 'TRF_' . $reference,
            'reason' => ($this->verified[$reference] ?? null) === 'failed' ? 'Transfer reversed' : null,
            'raw' => ['reference' => $reference],
        ];
    }
}

/**
 * A provider where creators add their bank details on the provider's own pages, like Stripe Connect.
 */
class FakeHostedGateway extends FakePayoutGateway implements HostsPayoutAccounts
{
    public bool $onboarded = false;

    public int $accounts = 0;

    public function methods(string $country): array
    {
        return [new PayoutMethod('hosted', 'Bank account through Stripe', 'express', PayoutMethod::LOOKUP_HOSTED)];
    }

    public function onboard(User $user, string $country, ?string $accountId, string $returnUrl): array
    {
        $accountId ??= 'acct_' . (++$this->accounts);

        return ['account_id' => $accountId, 'url' => "https://connect.example.test/{$accountId}?return=" . urlencode($returnUrl)];
    }

    public function ready(string $accountId): bool
    {
        return $this->onboarded;
    }
}

class PayoutTest extends TestCase
{
    use DatabaseTransactions;

    protected FakePayoutGateway $gateway;

    protected FakePayoutGateway $flutterwave;

    protected FakeHostedGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePayoutGateway;
        $this->flutterwave = new FakePayoutGateway('flutterwave', ['NG' => 'NGN', 'GH' => 'GHS'], 0.01, PayoutMethod::LOOKUP_NONE);
        $this->stripe = new FakeHostedGateway('stripe', ['GB' => 'USD'], 0.0007);
        $this->app->instance(PayoutProviders::class, new PayoutProviders([$this->gateway, $this->flutterwave, $this->stripe]));

        PayoutSettings::update(['paystack_payouts_enabled' => true, 'flutterwave_payouts_enabled' => true, 'stripe_payouts_enabled' => true]);
        Cache::flush();
        $this->setting('app_currency', 'NGN');
        $this->setting('min_payout_amount', 1000);
        $this->setting('payout_hold_days', 7);
        $this->setting('payout_auto_approve', false);
    }

    protected function setting(string $key, mixed $value): void
    {
        Configuration::where('key', $key)
            ->update(['value' => $value]);
        Cache::forget('configuration::build');
    }

    /**
     * Controllers keep the user they were built with, so drop them between users.
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

    protected function earn(User $creator, float $share, int $daysAgo = 30): Transaction
    {
        $transaction = new Transaction([
            'reference' => 'TRX-' . uniqid(),
            'purpose' => 'tip',
            'method' => 'paystack',
            'status' => 'complete',
            'amount' => $share,
            'due' => $share,
            'recipient_id' => $creator->id,
            'recipient_share' => $share,
            'paid_at' => now()->subDays($daysAgo),
        ]);
        $transaction->user_id = User::factory()
            ->create()->id;
        $transaction
            ->transactable()
            ->associate($creator);
        $transaction->save();

        return $transaction;
    }

    protected function creatorWithAccount(float $cleared = 10000, float $clearing = 0): User
    {
        $creator = User::factory()
            ->create();
        $this->earn($creator, $cleared);

        if ($clearing) {
            $this->earn($creator, $clearing, 1);
        }

        $this->actingAsFresh($creator);
        $this
            ->putJson('/api/account/payouts/account', $this->nigerianAccount())
            ->assertOk();

        return $creator;
    }

    /**
     * @return array<string, string>
     */
    protected function nigerianAccount(string $number = '0123456789', string $bank = '058'): array
    {
        return ['country' => 'NG', 'method' => 'bank', 'bank_code' => $bank, 'account_number' => $number];
    }

    protected function admin(): User
    {
        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();
        Permission::findOrCreate('manage-payouts', 'web');

        $admin = User::factory()
            ->create();
        $admin->givePermissionTo('manage-payouts');

        return $this->actingAsFresh($admin);
    }

    public function test_the_balance_only_counts_cleared_earnings(): void
    {
        $creator = User::factory()
            ->create();
        $this->earn($creator, 6000);
        $this->earn($creator, 2500, 2);
        $this->actingAsFresh($creator);

        $this->getJson('/api/account/payouts')
            ->assertOk()
            ->assertJsonPath('summary.earned', 8500)
            ->assertJsonPath('summary.clearing', 2500)
            ->assertJsonPath('summary.available', 6000)
            ->assertJsonPath('summary.holdDays', 7)
            ->assertJsonPath('account', null);

        $this->setting('payout_hold_days', 0);

        $this
            ->getJson('/api/account/payouts')
            ->assertJsonPath('summary.available', 8500);
    }

    public function test_a_creator_saves_a_bank_account_after_confirming_the_name(): void
    {
        $this->actingAsFresh(User::factory()->create());

        $this->getJson('/api/account/payouts/banks?country=NG&method=bank')
            ->assertOk()
            ->assertJsonPath('data.1.name', 'GTBank');

        $this->postJson('/api/account/payouts/account/resolve', $this->nigerianAccount())
            ->assertOk()
            ->assertJsonPath('data.accountName', 'ADA LOVELACE')
            ->assertJsonPath('data.bankName', 'GTBank');

        $this->postJson('/api/account/payouts/account/resolve', $this->nigerianAccount(bank: '999'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('bank_code');

        $this->putJson('/api/account/payouts/account', $this->nigerianAccount('0000000000'))
            ->assertUnprocessable();

        $this->putJson('/api/account/payouts/account', $this->nigerianAccount('12345'))
            ->assertJsonValidationErrors('account_number');

        $this->putJson('/api/account/payouts/account', $this->nigerianAccount())
            ->assertOk()
            ->assertJsonPath('data.provider', 'paystack')
            ->assertJsonPath('data.accountName', 'ADA LOVELACE')
            ->assertJsonPath('data.accountNumber', '******6789')
            ->assertJsonMissingPath('data.recipientCode');

        // Saving the same account again reuses the Paystack recipient.
        $this
            ->putJson('/api/account/payouts/account', $this->nigerianAccount())
            ->assertOk();
        $this->assertSame(1, $this->gateway->recipients);

        $this
            ->deleteJson('/api/account/payouts/account')
            ->assertOk();
        $this
            ->getJson('/api/account/payouts/account')
            ->assertJsonPath('data', []);
    }

    public function test_a_withdrawal_needs_an_account_and_enough_cleared_balance(): void
    {
        $creator = User::factory()
            ->create();
        $this->earn($creator, 5000);
        $this->earn($creator, 5000, 1);
        $this->actingAsFresh($creator);

        $this->postJson('/api/account/payouts', ['amount' => 2000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account');

        $this
            ->putJson('/api/account/payouts/account', $this->nigerianAccount())
            ->assertOk();

        $this
            ->postJson('/api/account/payouts', ['amount' => 500])
            ->assertJsonValidationErrors('amount');
        $this
            ->postJson('/api/account/payouts', ['amount' => 6000])
            ->assertJsonValidationErrors('amount');

        $this->postJson('/api/account/payouts', ['amount' => 3000])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.bank.accountNumber', '******6789')
            ->assertJsonPath('summary.available', 2000)
            ->assertJsonPath('summary.inFlight', 3000);

        // What is already requested can't be requested again.
        $this
            ->postJson('/api/account/payouts', ['amount' => 2500])
            ->assertJsonValidationErrors('amount');
        $this
            ->postJson('/api/account/payouts', ['amount' => 2000])
            ->assertCreated();

        $this->assertSame([], $this->gateway->transfers());
        $this
            ->getJson('/api/account/payouts')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('summary.available', 0);
    }

    public function test_a_creator_can_cancel_a_pending_withdrawal_but_not_someone_elses(): void
    {
        $creator = $this->creatorWithAccount(5000);
        $id = $this
            ->postJson('/api/account/payouts', ['amount' => 5000])
            ->json('data.id');

        $this->actingAsFresh(User::factory()->create());
        $this
            ->deleteJson("/api/account/payouts/{$id}")
            ->assertNotFound();

        $this->actingAsFresh($creator);
        $this->deleteJson("/api/account/payouts/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('summary.available', 5000);

        $this
            ->deleteJson("/api/account/payouts/{$id}")
            ->assertUnprocessable();
    }

    public function test_only_payout_admins_review_withdrawals(): void
    {
        $creator = $this->creatorWithAccount(5000);
        $id = $this
            ->postJson('/api/account/payouts', ['amount' => 4000])
            ->json('data.id');

        $this
            ->postJson("/api/admin/payouts/{$id}/approve")
            ->assertForbidden();
        $this
            ->getJson('/api/admin/payouts')
            ->assertForbidden();

        $admin = $this->admin();

        $this->getJson('/api/admin/payouts?status=pending')
            ->assertOk()
            ->assertJsonPath('summary.pendingCount', 1)
            ->assertJsonPath('data.0.user.id', $creator->id);

        $this->postJson("/api/admin/payouts/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.reviewer.id', $admin->id);

        $this->assertCount(1, $this->gateway->transfers());

        // Approving twice doesn't send twice.
        $this
            ->postJson("/api/admin/payouts/{$id}/approve")
            ->assertUnprocessable();
        $this->assertCount(1, $this->gateway->transfers());
    }

    public function test_a_rejected_withdrawal_goes_back_to_the_balance(): void
    {
        $creator = $this->creatorWithAccount(5000);
        $id = $this
            ->postJson('/api/account/payouts', ['amount' => 4000])
            ->json('data.id');

        $this->admin();
        $this->postJson("/api/admin/payouts/{$id}/reject", ['reason' => 'Suspicious tips'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.reason', 'Suspicious tips');

        $this->actingAsFresh($creator);
        $this
            ->getJson('/api/account/payouts')
            ->assertJsonPath('summary.available', 5000);
    }

    public function test_withdrawals_can_be_sent_without_approval(): void
    {
        $this->setting('payout_auto_approve', true);
        $this->gateway->transferStatus = 'paid';

        $this->creatorWithAccount(5000);

        $this->postJson('/api/account/payouts', ['amount' => 1500])
            ->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('summary.withdrawn', 1500)
            ->assertJsonPath('summary.available', 3500);
    }

    public function test_a_transfer_paystack_refuses_fails_and_frees_the_balance(): void
    {
        $this->setting('payout_auto_approve', true);
        $this->gateway->transferStatus = 'refuse';

        $this->creatorWithAccount(5000);

        $this->postJson('/api/account/payouts', ['amount' => 5000])
            ->assertCreated()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.reason', 'Your balance is not enough to fulfil this request')
            ->assertJsonPath('summary.available', 5000);
    }

    public function test_the_webhook_settles_and_reverses_transfers_after_asking_paystack(): void
    {
        $this->setting('payout_auto_approve', true);
        $this->creatorWithAccount(5000);

        $reference = $this
            ->postJson('/api/account/payouts', ['amount' => 5000])
            ->json('data.reference');
        $hook = fn(string $event) => $this->postJson('/api/payments/paystack/webhook', ['event' => $event, 'data' => ['reference' => $reference]], [
            'x-paystack-signature' => 'unused',
        ]);

        // The body alone isn't trusted: Paystack still says it's processing.
        $this->withoutWebhookSignature();
        $hook('transfer.success')->assertOk();
        $this->assertSame(PayoutStatus::PROCESSING, Payout::where('reference', $reference)->first()->status);

        $this->gateway->verified[$reference] = 'paid';
        $hook('transfer.success')->assertOk();
        $this->assertSame(PayoutStatus::PAID, Payout::where('reference', $reference)->first()->status);

        $this->gateway->verified[$reference] = 'failed';
        $hook('transfer.reversed')->assertOk();

        $payout = Payout::where('reference', $reference)
            ->first();
        $this->assertSame(PayoutStatus::FAILED, $payout->status);
        $this->assertSame('Transfer reversed', $payout->failure_reason);

        $this
            ->getJson('/api/account/payouts')
            ->assertJsonPath('summary.available', 5000);
    }

    public function test_the_sync_command_resends_transfers_paystack_never_received(): void
    {
        $this->setting('payout_auto_approve', true);
        $this->creatorWithAccount(5000);

        $reference = $this
            ->postJson('/api/account/payouts', ['amount' => 2000])
            ->json('data.reference');
        Payout::where('reference', $reference)
            ->update(['updated_at' => now()->subHour()]);

        $this->gateway->verified[$reference] = 'missing';
        $this
            ->artisan('payouts:sync')
            ->assertSuccessful();

        // Sent again with the same reference, so Paystack can't pay it twice.
        $this->assertSame([$reference, $reference], $this->gateway->transfers());

        $this->gateway->verified[$reference] = 'paid';
        Payout::where('reference', $reference)
            ->update(['updated_at' => now()->subHour()]);
        $this
            ->artisan('payouts:sync')
            ->assertSuccessful();

        $this->assertSame(PayoutStatus::PAID, Payout::where('reference', $reference)->first()->status);
    }

    public function test_the_creator_is_notified_when_a_withdrawal_is_paid_or_rejected(): void
    {
        Notification::fake();

        $creator = $this->creatorWithAccount(5000);
        $first = $this
            ->postJson('/api/account/payouts', ['amount' => 2000])
            ->json('data.id');
        $second = $this
            ->postJson('/api/account/payouts', ['amount' => 2000])
            ->json('data.id');

        Notification::assertNothingSentTo($creator);

        $this->admin();
        $this->gateway->transferStatus = 'paid';
        $this
            ->postJson("/api/admin/payouts/{$first}/approve")
            ->assertOk();
        $this
            ->postJson("/api/admin/payouts/{$second}/reject", ['reason' => 'Duplicate request'])
            ->assertOk();

        Notification::assertSentTo($creator, PayoutNotification::class, 2);
        Notification::assertSentTo(
            $creator,
            PayoutNotification::class,
            fn(PayoutNotification $n) => str_contains($n->content($creator)['body'], 'Duplicate request')
        );
    }

    public function test_each_country_is_paid_by_the_first_provider_that_covers_it(): void
    {
        $this->actingAsFresh(User::factory()->create());

        $countries = collect($this->getJson('/api/account/payouts/countries')->assertOk()->json('data'))
            ->keyBy('code');

        // Paystack wins Nigeria over Flutterwave; Ghana and the UK go to the others.
        $this->assertSame('paystack', $countries['NG']['provider']);
        $this->assertSame('flutterwave', $countries['GH']['provider']);
        $this->assertSame(['currency' => 'GHS', 'name' => 'Ghana'], ['currency' => $countries['GH']['currency'], 'name' => $countries['GH']['name']]);
        $this->assertSame('hosted', $countries['GB']['methods'][0]['lookup']);

        PayoutSettings::update(['paystack_payouts_enabled' => false]);
        $this->assertSame('flutterwave', collect($this->getJson('/api/account/payouts/countries')->json('data'))->firstWhere('code', 'NG')['provider']);

        $this
            ->getJson('/api/account/payouts/banks?country=US&method=bank')
            ->assertJsonValidationErrors('country');
    }

    public function test_a_withdrawal_abroad_is_converted_once_at_the_rate_when_it_is_sent(): void
    {
        $creator = User::factory()
            ->create();
        $this->earn($creator, 10000);
        $this->actingAsFresh($creator);

        // Flutterwave can't look Ghanaian names up, so the creator types it.
        $ghana = ['country' => 'GH', 'method' => 'bank', 'bank_code' => '058', 'account_number' => '0123456789'];
        $this
            ->putJson('/api/account/payouts/account', $ghana)
            ->assertJsonValidationErrors('account_name');

        $this->putJson('/api/account/payouts/account', [...$ghana, 'account_name' => 'Kofi Mensah'])
            ->assertOk()
            ->assertJsonPath('data.provider', 'flutterwave')
            ->assertJsonPath('data.country', 'GH')
            ->assertJsonPath('data.currency', 'GHS')
            ->assertJsonPath('data.accountName', 'Kofi Mensah');

        $id = $this
            ->postJson('/api/account/payouts', ['amount' => 5000])
            ->assertCreated()
            ->json('data.id');

        $this->admin();
        $this->flutterwave->transferStatus = 'processing';
        $this->postJson("/api/admin/payouts/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.amount', 5000)
            ->assertJsonPath('data.payoutAmount', 50)
            ->assertJsonPath('data.payoutCurrency', 'GHS')
            ->assertJsonPath('data.provider', 'flutterwave');

        $sent = $this->flutterwave->sent[0];
        $this->assertSame([50.0, 'GHS', 'NGN', 'RCP_1'], [$sent->amount, $sent->currency, $sent->sourceCurrency, $sent->recipient]);
        $this->assertSame([], $this->gateway->transfers());

        // A resend after the rate moved still sends the same amount.
        $this->flutterwave->exchangeRate = 0.02;
        $this->flutterwave->verified[$sent->reference] = 'missing';
        $this
            ->postJson("/api/admin/payouts/{$id}/sync")
            ->assertOk();
        $this->assertSame(50.0, $this->flutterwave->sent[1]->amount);
    }

    public function test_a_hosted_account_needs_onboarding_before_withdrawing(): void
    {
        config(['app.frontend_url' => 'https://collab.example.test']);

        $creator = User::factory()
            ->create();
        $this->earn($creator, 10000);
        $this->actingAsFresh($creator);

        $this->postJson('/api/account/payouts/account/connect', ['country' => 'GB', 'return_url' => 'https://evil.example.com/back'])
            ->assertUnprocessable();

        $this->postJson('/api/account/payouts/account/connect', ['country' => 'NG', 'return_url' => 'https://collab.example.test/account/earnings'])
            ->assertJsonValidationErrors('country');

        $url = $this->postJson('/api/account/payouts/account/connect', ['country' => 'GB', 'return_url' => 'https://collab.example.test/account/earnings'])
            ->assertOk()
            ->json('data.url');
        $this->assertStringStartsWith('https://connect.example.test/acct_1', $url);

        $this->getJson('/api/account/payouts/account')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.provider', 'stripe')
            ->assertJsonPath('data.accountNumber', null);

        $this
            ->postJson('/api/account/payouts', ['amount' => 5000])
            ->assertJsonValidationErrors('account');

        // Continuing reuses the same account.
        $this
            ->postJson('/api/account/payouts/account/connect', ['country' => 'GB', 'return_url' => 'https://collab.example.test/account/earnings'])
            ->assertOk();
        $this->assertSame(1, $this->stripe->accounts);

        $this->stripe->onboarded = true;
        $this
            ->getJson('/api/account/payouts')
            ->assertJsonPath('account.status', 'active');

        $this->setting('payout_auto_approve', true);
        $this->stripe->transferStatus = 'paid';
        $this->postJson('/api/account/payouts', ['amount' => 5000])
            ->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payoutCurrency', 'USD')
            ->assertJsonPath('data.payoutAmount', 3.5);

        $this->assertSame('acct_1', $this->stripe->sent[0]->recipient);
    }

    public function test_flutterwave_and_stripe_webhooks_settle_their_own_payouts(): void
    {
        config(['services.flutterwave.webhook_hash' => 'fw-hash', 'services.stripe.webhook_secret' => 'whsec_test']);

        $creator = User::factory()
            ->create();
        $this->earn($creator, 10000);
        $this->actingAsFresh($creator);
        $this
            ->putJson('/api/account/payouts/account', ['country' => 'GH', 'method' => 'bank', 'bank_code' => '058', 'account_number' => '0123456789', 'account_name' => 'Kofi Mensah'])
            ->assertOk();

        $this->setting('payout_auto_approve', true);
        $reference = $this
            ->postJson('/api/account/payouts', ['amount' => 2000])
            ->json('data.reference');
        $flutterwave = fn(?string $hash) => $this->postJson('/api/payouts/flutterwave/webhook', [
            'event' => 'transfer.completed',
            'data' => ['reference' => $reference, 'status' => 'SUCCESSFUL'],
        ], $hash ? ['verif-hash' => $hash] : []);

        $flutterwave(null)->assertUnauthorized();
        $flutterwave('wrong')->assertUnauthorized();

        // The body alone isn't trusted.
        $flutterwave('fw-hash')->assertOk();
        $this->assertSame(PayoutStatus::PROCESSING, Payout::where('reference', $reference)->first()->status);

        $this->flutterwave->verified[$reference] = 'paid';
        $flutterwave('fw-hash')->assertOk();
        $this->assertSame(PayoutStatus::PAID, Payout::where('reference', $reference)->first()->status);

        // A Stripe event can't touch a Flutterwave payout.
        $payload = json_encode(['id' => 'evt_1', 'object' => 'event', 'type' => 'transfer.reversed', 'data' => ['object' => [
            'id' => 'tr_1', 'object' => 'transfer', 'transfer_group' => $reference, 'metadata' => ['reference' => $reference],
        ]]]);
        $time = time();
        $signature = "t={$time},v1=" . hash_hmac('sha256', "{$time}.{$payload}", 'whsec_test');

        $this->stripe->verified[$reference] = 'failed';
        $this->call('POST', '/api/payouts/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=bad', 'CONTENT_TYPE' => 'application/json'], $payload)
            ->assertUnauthorized();
        $this->call('POST', '/api/payouts/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $payload)
            ->assertOk();
        $this->assertSame(PayoutStatus::PAID, Payout::where('reference', $reference)->first()->status);

        Payout::where('reference', $reference)
            ->update(['provider' => 'stripe']);
        $this->call('POST', '/api/payouts/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $payload)
            ->assertOk();
        $this->assertSame(PayoutStatus::FAILED, Payout::where('reference', $reference)->first()->status);
    }

    public function test_only_configuration_admins_change_payout_providers(): void
    {
        $this->admin();
        $this
            ->getJson('/api/admin/payouts/settings')
            ->assertForbidden();

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();
        Permission::findOrCreate('manage-configuration', 'web');
        $admin = User::factory()
            ->create();
        $admin->givePermissionTo('manage-configuration');
        $this->actingAsFresh($admin);

        config(['services.flutterwave.secret_key' => 'FLWSECK-123']);

        $this->putJson('/api/admin/payouts/settings', ['flutterwave_secret_key' => 'ignored', 'stripe_currency' => 'eur', 'stripe_payouts_enabled' => false])
            ->assertAccepted()
            ->assertJsonPath('data.fields.stripe_currency.value', 'EUR')
            ->assertJsonPath('data.keys.flutterwave.0.env', 'FLUTTERWAVE_SECRET_KEY')
            ->assertJsonPath('data.keys.flutterwave.0.isSet', true)
            ->assertDontSee('FLWSECK-123');

        $this->assertDatabaseMissing('configurations', ['key' => 'flutterwave_secret_key']);
        $this->assertNotContains('GB', collect($this->getJson('/api/admin/payouts/settings')->json('data.countries'))->pluck('code'));
    }

    /**
     * Let any webhook signature through, since these tests check what happens after it.
     */
    protected function withoutWebhookSignature(): void
    {
        $gateway = $this->createStub(PaymentGateway::class);
        $gateway
            ->method('key')
            ->willReturn('paystack');
        $gateway
            ->method('validWebhook')
            ->willReturn(true);

        $this->app->instance(PaymentGateway::class, $gateway);
    }
}
