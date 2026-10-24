<?php

namespace Tests\Feature;

use App\Enums\PayoutStatus;
use App\Models\Configuration;
use App\Models\Payout;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PayoutGateway;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FakePayoutGateway implements PayoutGateway
{
    /** What the next transfer call answers with, or 'refuse' to throw */
    public string $transferStatus = 'processing';

    /** @var array<string, string> reference => status verifyTransfer answers with */
    public array $verified = [];

    /** @var array<int, string> */
    public array $transfers = [];

    public int $recipients = 0;

    public function banks(string $currency): array
    {
        return [
            ['name' => 'Access Bank', 'code' => '044'],
            ['name' => 'GTBank', 'code' => '058'],
        ];
    }

    public function resolveAccount(string $accountNumber, string $bankCode): string
    {
        if ($accountNumber === '0000000000') {
            throw ValidationException::withMessages(['account_number' => 'Could not resolve account name.']);
        }

        return 'ADA LOVELACE';
    }

    public function createRecipient(string $name, string $accountNumber, string $bankCode, string $currency): string
    {
        return 'RCP_'.(++$this->recipients);
    }

    public function transfer(float $amount, string $currency, string $recipientCode, string $reference, string $reason): array
    {
        if ($this->transferStatus === 'refuse') {
            throw ValidationException::withMessages(['payout' => 'Your balance is not enough to fulfil this request']);
        }

        $this->transfers[] = $reference;

        return ['status' => $this->transferStatus, 'transfer_code' => 'TRF_'.$reference, 'reason' => null, 'raw' => ['reference' => $reference]];
    }

    public function verifyTransfer(string $reference): array
    {
        return [
            'status' => $this->verified[$reference] ?? 'processing',
            'transfer_code' => 'TRF_'.$reference,
            'reason' => ($this->verified[$reference] ?? null) === 'failed' ? 'Transfer reversed' : null,
            'raw' => ['reference' => $reference],
        ];
    }
}

class PayoutTest extends TestCase
{
    use DatabaseTransactions;

    protected FakePayoutGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePayoutGateway;
        $this->app->instance(PayoutGateway::class, $this->gateway);

        Cache::forget('payouts:banks:NGN');
        $this->setting('min_payout_amount', 1000);
        $this->setting('payout_hold_days', 7);
        $this->setting('payout_auto_approve', false);
    }

    protected function setting(string $key, mixed $value): void
    {
        Configuration::where('key', $key)->update(['value' => $value]);
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
            'reference' => 'TRX-'.uniqid(),
            'purpose' => 'tip',
            'method' => 'paystack',
            'status' => 'complete',
            'amount' => $share,
            'due' => $share,
            'recipient_id' => $creator->id,
            'recipient_share' => $share,
            'paid_at' => now()->subDays($daysAgo),
        ]);
        $transaction->user_id = User::factory()->create()->id;
        $transaction->transactable()->associate($creator);
        $transaction->save();

        return $transaction;
    }

    protected function creatorWithAccount(float $cleared = 10000, float $clearing = 0): User
    {
        $creator = User::factory()->create();
        $this->earn($creator, $cleared);

        if ($clearing) {
            $this->earn($creator, $clearing, 1);
        }

        $this->actingAsFresh($creator);
        $this->putJson('/api/account/payouts/account', ['bank_code' => '058', 'account_number' => '0123456789'])->assertOk();

        return $creator;
    }

    protected function admin(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('manage-payouts', 'web');

        $admin = User::factory()->create();
        $admin->givePermissionTo('manage-payouts');

        return $this->actingAsFresh($admin);
    }

    public function test_the_balance_only_counts_cleared_earnings(): void
    {
        $creator = User::factory()->create();
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

        $this->getJson('/api/account/payouts')->assertJsonPath('summary.available', 8500);
    }

    public function test_a_creator_saves_a_bank_account_after_confirming_the_name(): void
    {
        $this->actingAsFresh(User::factory()->create());

        $this->getJson('/api/account/payouts/banks')
            ->assertOk()
            ->assertJsonPath('data.1.name', 'GTBank');

        $this->postJson('/api/account/payouts/account/resolve', ['bank_code' => '058', 'account_number' => '0123456789'])
            ->assertOk()
            ->assertJsonPath('data.account_name', 'ADA LOVELACE')
            ->assertJsonPath('data.bank_name', 'GTBank');

        $this->postJson('/api/account/payouts/account/resolve', ['bank_code' => '999', 'account_number' => '0123456789'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('bank_code');

        $this->putJson('/api/account/payouts/account', ['bank_code' => '058', 'account_number' => '0000000000'])
            ->assertUnprocessable();

        $this->putJson('/api/account/payouts/account', ['bank_code' => '058', 'account_number' => '12345'])
            ->assertJsonValidationErrors('account_number');

        $this->putJson('/api/account/payouts/account', ['bank_code' => '058', 'account_number' => '0123456789'])
            ->assertOk()
            ->assertJsonPath('data.accountName', 'ADA LOVELACE')
            ->assertJsonPath('data.accountNumber', '******6789')
            ->assertJsonMissingPath('data.recipientCode');

        // Saving the same account again reuses the Paystack recipient.
        $this->putJson('/api/account/payouts/account', ['bank_code' => '058', 'account_number' => '0123456789'])->assertOk();
        $this->assertSame(1, $this->gateway->recipients);

        $this->deleteJson('/api/account/payouts/account')->assertOk();
        $this->getJson('/api/account/payouts/account')->assertJsonPath('data', null);
    }

    public function test_a_withdrawal_needs_an_account_and_enough_cleared_balance(): void
    {
        $creator = User::factory()->create();
        $this->earn($creator, 5000);
        $this->earn($creator, 5000, 1);
        $this->actingAsFresh($creator);

        $this->postJson('/api/account/payouts', ['amount' => 2000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account');

        $this->putJson('/api/account/payouts/account', ['bank_code' => '058', 'account_number' => '0123456789'])->assertOk();

        $this->postJson('/api/account/payouts', ['amount' => 500])->assertJsonValidationErrors('amount');
        $this->postJson('/api/account/payouts', ['amount' => 6000])->assertJsonValidationErrors('amount');

        $this->postJson('/api/account/payouts', ['amount' => 3000])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.bank.accountNumber', '******6789')
            ->assertJsonPath('summary.available', 2000)
            ->assertJsonPath('summary.inFlight', 3000);

        // What is already requested can't be requested again.
        $this->postJson('/api/account/payouts', ['amount' => 2500])->assertJsonValidationErrors('amount');
        $this->postJson('/api/account/payouts', ['amount' => 2000])->assertCreated();

        $this->assertSame([], $this->gateway->transfers);
        $this->getJson('/api/account/payouts')->assertJsonCount(2, 'data')->assertJsonPath('summary.available', 0);
    }

    public function test_a_creator_can_cancel_a_pending_withdrawal_but_not_someone_elses(): void
    {
        $creator = $this->creatorWithAccount(5000);
        $id = $this->postJson('/api/account/payouts', ['amount' => 5000])->json('data.id');

        $this->actingAsFresh(User::factory()->create());
        $this->deleteJson("/api/account/payouts/{$id}")->assertNotFound();

        $this->actingAsFresh($creator);
        $this->deleteJson("/api/account/payouts/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('summary.available', 5000);

        $this->deleteJson("/api/account/payouts/{$id}")->assertUnprocessable();
    }

    public function test_only_payout_admins_review_withdrawals(): void
    {
        $creator = $this->creatorWithAccount(5000);
        $id = $this->postJson('/api/account/payouts', ['amount' => 4000])->json('data.id');

        $this->postJson("/api/admin/payouts/{$id}/approve")->assertForbidden();
        $this->getJson('/api/admin/payouts')->assertForbidden();

        $admin = $this->admin();

        $this->getJson('/api/admin/payouts?status=pending')
            ->assertOk()
            ->assertJsonPath('summary.pendingCount', 1)
            ->assertJsonPath('data.0.user.id', $creator->id);

        $this->postJson("/api/admin/payouts/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.reviewer.id', $admin->id);

        $this->assertCount(1, $this->gateway->transfers);

        // Approving twice doesn't send twice.
        $this->postJson("/api/admin/payouts/{$id}/approve")->assertUnprocessable();
        $this->assertCount(1, $this->gateway->transfers);
    }

    public function test_a_rejected_withdrawal_goes_back_to_the_balance(): void
    {
        $creator = $this->creatorWithAccount(5000);
        $id = $this->postJson('/api/account/payouts', ['amount' => 4000])->json('data.id');

        $this->admin();
        $this->postJson("/api/admin/payouts/{$id}/reject", ['reason' => 'Suspicious tips'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.reason', 'Suspicious tips');

        $this->actingAsFresh($creator);
        $this->getJson('/api/account/payouts')->assertJsonPath('summary.available', 5000);
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

        $reference = $this->postJson('/api/account/payouts', ['amount' => 5000])->json('data.reference');
        $hook = fn (string $event) => $this->postJson('/api/payments/paystack/webhook', ['event' => $event, 'data' => ['reference' => $reference]], [
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

        $payout = Payout::where('reference', $reference)->first();
        $this->assertSame(PayoutStatus::FAILED, $payout->status);
        $this->assertSame('Transfer reversed', $payout->failure_reason);

        $this->getJson('/api/account/payouts')->assertJsonPath('summary.available', 5000);
    }

    public function test_the_sync_command_resends_transfers_paystack_never_received(): void
    {
        $this->setting('payout_auto_approve', true);
        $this->creatorWithAccount(5000);

        $reference = $this->postJson('/api/account/payouts', ['amount' => 2000])->json('data.reference');
        Payout::where('reference', $reference)->update(['updated_at' => now()->subHour()]);

        $this->gateway->verified[$reference] = 'missing';
        $this->artisan('payouts:sync')->assertSuccessful();

        // Sent again with the same reference, so Paystack can't pay it twice.
        $this->assertSame([$reference, $reference], $this->gateway->transfers);

        $this->gateway->verified[$reference] = 'paid';
        Payout::where('reference', $reference)->update(['updated_at' => now()->subHour()]);
        $this->artisan('payouts:sync')->assertSuccessful();

        $this->assertSame(PayoutStatus::PAID, Payout::where('reference', $reference)->first()->status);
    }

    /**
     * Let any webhook signature through, since these tests check what happens after it.
     */
    protected function withoutWebhookSignature(): void
    {
        $gateway = $this->createStub(PaymentGateway::class);
        $gateway->method('validWebhook')->willReturn(true);

        $this->app->instance(PaymentGateway::class, $gateway);
    }
}
