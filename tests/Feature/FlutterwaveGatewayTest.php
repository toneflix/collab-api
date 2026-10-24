<?php

namespace Tests\Feature;

use App\Services\Payment\FlutterwaveGateway;
use App\Services\Payment\PayoutTransfer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FlutterwaveGatewayTest extends TestCase
{
    use DatabaseTransactions;

    protected FlutterwaveGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.flutterwave.secret_key' => 'FLWSECK_TEST-1']);
        $this->gateway = new FlutterwaveGateway;
    }

    public function test_it_sends_a_converted_transfer_and_reads_its_status(): void
    {
        Http::fake([
            'api.flutterwave.com/v3/transfers' => Http::response(['status' => 'success', 'data' => ['id' => 4821, 'status' => 'NEW']]),
            'api.flutterwave.com/v3/transfers/4821' => Http::response(['status' => 'success', 'data' => ['id' => 4821, 'status' => 'FAILED', 'complete_message' => 'Account resolve failed']]),
        ]);

        $result = $this->gateway->transfer(new PayoutTransfer(
            amount: 50,
            currency: 'GHS',
            sourceCurrency: 'NGN',
            recipient: 'MTN:233241234567',
            reference: 'po_abc',
            reason: 'Earnings',
            country: 'GH',
            method: 'mobile_money',
            destination: ['account_name' => 'Kofi Mensah'],
        ));

        $this->assertSame(['processing', '4821'], [$result['status'], $result['transfer_code']]);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.flutterwave.com/v3/transfers'
            && $r['account_bank'] === 'MTN'
            && $r['account_number'] === '233241234567'
            && $r['currency'] === 'GHS'
            && $r['debit_currency'] === 'NGN'
            && $r['beneficiary_name'] === 'Kofi Mensah'
            && $r['reference'] === 'po_abc'
            && $r->hasHeader('Authorization', 'Bearer FLWSECK_TEST-1'));

        $status = $this->gateway->verifyTransfer('po_abc', '4821');
        $this->assertSame(['failed', 'Account resolve failed'], [$status['status'], $status['reason']]);

        $this->assertSame('missing', $this->gateway->verifyTransfer('po_abc')['status']);
    }

    public function test_it_reads_the_rate_and_refuses_without_keys(): void
    {
        Http::fake([
            'api.flutterwave.com/v3/transfers/rates*' => Http::response(['data' => ['rate' => 0.0095, 'source' => ['currency' => 'NGN', 'amount' => 105263.16], 'destination' => ['currency' => 'GHS', 'amount' => 1000]]]),
        ]);

        $this->assertEqualsWithDelta(0.0095, $this->gateway->rate('NGN', 'GHS'), 0.00001);
        $this->assertSame(1.0, $this->gateway->rate('NGN', 'NGN'));

        config(['services.flutterwave.secret_key' => null]);

        $this->expectException(ValidationException::class);
        $this->gateway->rate('NGN', 'KES');
    }
}
