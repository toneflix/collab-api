<?php

namespace Tests\Unit;

use App\Services\Payment\PaystackTransfers;
use PHPUnit\Framework\TestCase;
use Toneflix\Paystack\Contracts\TransportInterface;
use Toneflix\Paystack\Exception\ApiException;
use Toneflix\Paystack\Http\RawResponse;
use Toneflix\Paystack\Paystack;

class PaystackTransfersTest extends TestCase
{
    /**
     * A transport that records each request and answers with the queued bodies.
     */
    protected function transport(array $responses): TransportInterface
    {
        return new class($responses) implements TransportInterface
        {
            public array $sent = [];

            public function __construct(protected array $responses) {}

            public function send(string $method, string $url, array $headers, ?string $body): RawResponse
            {
                $this->sent[] = [$method, $url, $body];

                return new RawResponse(200, json_encode(array_shift($this->responses)));
            }
        };
    }

    public function test_it_calls_the_payout_endpoints(): void
    {
        $transport = $this->transport([
            ['status' => true, 'data' => [['name' => 'Access Bank', 'code' => '044']]],
            ['status' => true, 'data' => ['account_name' => 'JANE DOE']],
            ['status' => true, 'data' => ['verified' => true]],
            ['status' => true, 'data' => ['recipient_code' => 'RCP_1']],
            ['status' => true, 'data' => ['status' => 'pending']],
            ['status' => true, 'data' => ['status' => 'success']],
        ]);
        $api = new PaystackTransfers(new Paystack('sk_test_1', $transport));

        $this->assertSame('044', $api->banks(['currency' => 'NGN', 'perPage' => 100])->data[0]->code);
        $this->assertSame('JANE DOE', $api->resolveAccount(['account_number' => '0123456789', 'bank_code' => '044'])->data->account_name);
        $this->assertTrue($api->validateAccount(['account_number' => '1', 'country_code' => 'ZA'])->data->verified);
        $this->assertSame('RCP_1', $api->createRecipient(['type' => 'nuban'])->data->recipient_code);
        $this->assertSame('pending', $api->initiate(['amount' => 500])->data->status);
        $this->assertSame('success', $api->verify(['reference' => 'PO/1'])->data->status);

        $this->assertSame([
            ['GET', 'https://api.paystack.co/bank?currency=NGN&perPage=100'],
            ['GET', 'https://api.paystack.co/bank/resolve?account_number=0123456789&bank_code=044'],
            ['POST', 'https://api.paystack.co/bank/validate'],
            ['POST', 'https://api.paystack.co/transferrecipient'],
            ['POST', 'https://api.paystack.co/transfer'],
            ['GET', 'https://api.paystack.co/transfer/verify/PO%2F1'],
        ], array_map(fn ($request) => [$request[0], $request[1]], $transport->sent));
    }

    public function test_a_refusal_throws_with_paystacks_message(): void
    {
        $api = new PaystackTransfers(new Paystack('sk_test_1', $this->transport([
            ['status' => false, 'message' => 'Could not resolve account name'],
        ])));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Could not resolve account name');

        $api->resolveAccount(['account_number' => '0123456789', 'bank_code' => '044']);
    }
}
