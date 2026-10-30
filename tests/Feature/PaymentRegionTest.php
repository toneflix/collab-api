<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Configuration;
use App\Models\Genre;
use App\Models\PaymentRegion;
use App\Models\Release;
use App\Models\Track;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PayoutSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PaymentRegionTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, PaymentRequest> */
    protected array $paystackRequests = [];

    /** What Flutterwave says about a payment looked up by reference */
    protected array $flutterwaveAnswer = ['status' => 'pending', 'amount' => 0, 'currency' => 'GHS'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PaymentGateway::class, $this->paystack());

        config([
            'app.frontend_url' => 'https://music.test',
            'services.flutterwave.public_key' => 'FLWPUBK_TEST-1',
            'services.flutterwave.secret_key' => 'FLWSECK_TEST-1',
            'services.flutterwave.webhook_hash' => 'fw-hash',
            'services.exchange_rates.provider' => 'exchangerate-api',
            'services.exchange_rates.key' => 'rates-key',
        ]);

        Configuration::where('key', 'app_currency')
            ->update(['value' => 'NGN']);
        PayoutSettings::update(['paystack_payments_enabled' => true, 'flutterwave_payments_enabled' => true]);
        Cache::flush();

        Http::fake([
            'api.flutterwave.com/v3/transactions/verify_by_reference*' => fn(Request $request) => Http::response([
                'status' => 'success',
                'data' => [...$this->flutterwaveAnswer, 'tx_ref' => $request['tx_ref']],
            ]),
            'api.flutterwave.com/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.test/pay/abc']]),
            'v6.exchangerate-api.com/*' => Http::response(['result' => 'success', 'conversion_rate' => 0.0095, 'conversion_result' => 0.0095]),
        ]);
    }

    protected function paystack(): PaymentGateway
    {
        $test = $this;

        return new class($test) implements PaymentGateway
        {
            public function __construct(protected PaymentRegionTest $test) {}

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
                $this->test->recordPaystack($request);

                return ['reference' => $request->reference, 'access_code' => 'code', 'authorization_url' => 'https://paystack.test'];
            }

            public function verify(string $reference): array
            {
                return ['paid' => false, 'final' => false, 'amount' => 0, 'currency' => null, 'raw' => []];
            }

            public function validWebhook(string $payload, ?string $signature): bool
            {
                return false;
            }
        };
    }

    public function recordPaystack(PaymentRequest $request): void
    {
        $this->paystackRequests[] = $request;
    }

    protected function release(float $price = 1500): Release
    {
        $genre = Genre::factory()
            ->create();

        return Release::factory()
            ->has(Track::factory()->state(['genre_id' => $genre->id]), 'tracks')
            ->create([
                'draft' => false,
                'price' => $price,
                'genre_id' => $genre->id,
                'user_id' => User::factory()->create()->id,
                'artist_id' => Artist::factory()->create()->id,
            ]);
    }

    protected function ghana(array $attributes = []): PaymentRegion
    {
        return PaymentRegion::create([
            'name' => 'Ghana',
            'countries' => ['GH'],
            'providers' => ['flutterwave'],
            'currency' => 'GHS',
            'exchange_rate' => 0.01,
            ...$attributes,
        ]);
    }

    protected function payer(string $country = 'Ghana'): User
    {
        $user = User::factory()
            ->create(['country' => $country]);

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Flutterwave's answer when a payment is looked up by reference.
     */
    protected function flutterwavePaid(float $amount, string $currency, string $status = 'successful'): void
    {
        $this->flutterwaveAnswer = ['status' => $status, 'amount' => $amount, 'currency' => $currency];
    }

    protected function sentTo(string $prefix): int
    {
        return Http::recorded(fn(Request $request) => str_starts_with($request->url(), $prefix))->count();
    }

    public function test_admins_manage_regions_and_a_country_sits_in_one_region(): void
    {
        $this->payer();
        $this
            ->getJson('/api/admin/payments/regions')
            ->assertForbidden();

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();
        Permission::findOrCreate('manage-configuration', 'web');

        $admin = User::factory()
            ->create();
        $admin->givePermissionTo('manage-configuration');
        Sanctum::actingAs($admin);

        $id = $this
            ->postJson('/api/admin/payments/regions', [
                'name' => 'West Africa',
                'countries' => ['gh', 'CI', 'SN'],
                'providers' => ['flutterwave'],
                'currency' => 'xof',
                'rate_markup' => 1.5,
            ])
            ->assertCreated()
            ->assertJsonPath('data.countries', ['GH', 'CI', 'SN'])
            ->assertJsonPath('data.currency', 'XOF')
            ->json('data.id');

        $this
            ->postJson('/api/admin/payments/regions', [
                'name' => 'Ghana',
                'countries' => ['GH'],
                'providers' => ['paystack'],
                'currency' => 'GHS',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('countries');

        $this
            ->postJson('/api/admin/payments/regions', [
                'name' => 'Nowhere',
                'countries' => ['NG'],
                'providers' => ['stripe'],
                'currency' => 'NGN',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('providers.0');

        $this
            ->putJson("/api/admin/payments/regions/{$id}", [
                'name' => 'West Africa',
                'countries' => ['GH', 'CI'],
                'providers' => ['flutterwave', 'paystack'],
                'currency' => 'XOF',
            ])
            ->assertAccepted()
            ->assertJsonPath('data.providers', ['flutterwave', 'paystack']);

        $this
            ->getJson('/api/admin/payments/regions')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this
            ->deleteJson("/api/admin/payments/regions/{$id}")
            ->assertAccepted();

        $this->assertDatabaseMissing('payment_regions', ['id' => $id]);
    }

    public function test_payers_outside_any_region_pay_in_the_app_currency_with_every_provider(): void
    {
        $this->ghana();
        $this->payer('France');

        $this
            ->getJson('/api/payments/options')
            ->assertOk()
            ->assertJsonPath('data.country', 'FR')
            ->assertJsonPath('data.currency', 'NGN')
            ->assertJsonPath('data.providers', ['paystack', 'flutterwave'])
            ->assertJsonPath('data.region', null);

        PayoutSettings::update(['flutterwave_payments_enabled' => false]);

        $this
            ->getJson('/api/payments/options')
            ->assertJsonPath('data.providers', ['paystack']);
    }

    public function test_a_region_charges_in_its_currency_through_its_providers(): void
    {
        $this->ghana();
        $release = $this->release(1500);
        $payer = $this->payer();

        $this
            ->getJson('/api/payments/options')
            ->assertOk()
            ->assertJsonPath('data.currency', 'GHS')
            ->assertJsonPath('data.rate', 0.01)
            ->assertJsonPath('data.providers', ['flutterwave'])
            ->assertJsonPath('data.region.name', 'Ghana');

        $this
            ->postJson('/api/payments', ['purpose' => 'release', 'id' => $release->slug, 'provider' => 'paystack'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('provider');

        $reference = $this
            ->postJson('/api/payments', ['purpose' => 'release', 'id' => $release->slug, 'inline' => true, 'return_path' => "/album/{$release->slug}"])
            ->assertCreated()
            ->assertJsonPath('data.provider', 'flutterwave')
            ->assertJsonPath('data.currency', 'GHS')
            ->assertJsonPath('data.chargeAmount', 15)
            ->assertJsonPath('data.amount', 1500)
            ->assertJsonPath('data.returnUrl', fn(string $url) => str_starts_with($url, 'https://music.test/payments/'))
            ->json('data.reference');

        // Inline payments start in the app's popup, so nothing is sent to Flutterwave yet.
        $this->assertSame(0, $this->sentTo('https://api.flutterwave.com'));

        $this->flutterwavePaid(15, 'GHS');

        $this
            ->postJson("/api/payments/{$reference}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'complete')
            ->assertJsonPath('data.returnPath', "/album/{$release->slug}");

        $transaction = Transaction::where('reference', $reference)
            ->firstOrFail();

        $this->assertSame(1500.0, $transaction->amount);
        $this->assertSame('flutterwave', $transaction->method);
        $this->assertTrue($release->fresh()->isUnlockedFor($payer));
    }

    public function test_a_payment_in_another_currency_or_short_of_the_charge_is_rejected(): void
    {
        $this->ghana();
        $release = $this->release(1500);
        $this->payer();

        $reference = fn() => $this
            ->postJson('/api/payments', ['purpose' => 'release', 'id' => $release->slug, 'inline' => true])
            ->assertCreated()
            ->json('data.reference');

        $wrongCurrency = $reference();
        $this->flutterwavePaid(15, 'NGN');
        $this
            ->postJson("/api/payments/{$wrongCurrency}/verify")
            ->assertStatus(400)
            ->assertJsonPath('data.status', 'rejected');

        $short = $reference();
        $this->flutterwavePaid(14.99, 'GHS');
        $this
            ->postJson("/api/payments/{$short}/verify")
            ->assertStatus(400)
            ->assertJsonPath('data.status', 'rejected');
    }

    public function test_flutterwave_standard_sends_the_payer_to_its_checkout_and_back_to_the_app(): void
    {
        $this->ghana();
        $release = $this->release(1500);
        $this->payer();

        $reference = $this
            ->postJson('/api/payments', ['purpose' => 'release', 'id' => $release->slug])
            ->assertCreated()
            ->assertJsonPath('data.authorizationUrl', 'https://checkout.flutterwave.test/pay/abc')
            ->json('data.reference');

        Http::assertSent(fn(Request $request) => $request->url() === 'https://api.flutterwave.com/v3/payments'
            && $request['tx_ref'] === $reference
            && $request['currency'] === 'GHS'
            && (float) $request['amount'] === 15.0
            && $request['redirect_url'] === "https://music.test/payments/{$reference}"
            && $request->hasHeader('Authorization', 'Bearer FLWSECK_TEST-1'));
    }

    public function test_live_rates_are_cached_and_carry_the_markup(): void
    {
        $this->ghana(['exchange_rate' => null, 'rate_markup' => 2]);
        $release = $this->release(1500);
        $this->payer();

        $this
            ->postJson('/api/payments', ['purpose' => 'release', 'id' => $release->slug, 'inline' => true])
            ->assertCreated()
            ->assertJsonPath('data.chargeAmount', 14.54);

        $this
            ->getJson('/api/payments/options')
            ->assertJsonPath('data.rate', fn($rate) => abs($rate - 0.00969) < 0.000001);

        $this->assertSame(1, $this->sentTo('https://v6.exchangerate-api.com/v6/rates-key/pair/NGN/GHS/1'));
    }

    public function test_the_flutterwave_webhook_settles_charges_after_asking_flutterwave(): void
    {
        $this->ghana();
        $release = $this->release(1500);
        $this->payer();

        $reference = $this
            ->postJson('/api/payments', ['purpose' => 'release', 'id' => $release->slug, 'inline' => true])
            ->json('data.reference');
        $webhook = fn(?string $hash) => $this->postJson('/api/payments/flutterwave/webhook', [
            'event' => 'charge.completed',
            'data' => ['tx_ref' => $reference, 'status' => 'successful', 'amount' => 15, 'currency' => 'GHS'],
        ], $hash ? ['verif-hash' => $hash] : []);

        $webhook('wrong')->assertUnauthorized();

        // The body alone isn't trusted.
        $this->flutterwavePaid(15, 'GHS', 'pending');
        $webhook('fw-hash')->assertOk();
        $this->assertSame('pending', Transaction::where('reference', $reference)->value('status'));

        $this->flutterwavePaid(15, 'GHS');
        $webhook('fw-hash')->assertOk();
        $this->assertSame('complete', Transaction::where('reference', $reference)->value('status'));
    }
}
