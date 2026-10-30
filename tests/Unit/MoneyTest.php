<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Toneflix\Money\Contracts\HttpClient;
use Toneflix\Money\Exceptions\DivisionByZeroException;
use Toneflix\Money\Exceptions\ExchangeException;
use Toneflix\Money\Exchange;
use Toneflix\Money\Money;
use Toneflix\Money\Providers\ExchangeRateApi;
use Toneflix\Money\Providers\FrankfurterApi;

class MoneyTest extends TestCase
{
    protected function tearDown(): void
    {
        Exchange::reset();

        parent::tearDown();
    }

    /**
     * An HTTP client that answers every request with `$body` and records the URLs.
     *
     * @param  array<string, mixed>  $body
     */
    protected function http(array $body): HttpClient
    {
        return new class($body) implements HttpClient
        {
            /** @var array<int, string> */
            public array $urls = [];

            public function __construct(protected array $body) {}

            public function getJson(string $url): array
            {
                $this->urls[] = $url;

                return $this->body;
            }
        };
    }

    public function test_it_formats_amounts(): void
    {
        $this->assertSame('$1,234.56', Money::of(1234.56, 'USD')->format());
        $this->assertSame('₦1,235', Money::of('1234.56', 'NGN')->whole());
        $this->assertSame('$1.2M', Money::of(1234567, 'USD')->compact());
        $this->assertSame('€999', Money::of(999, 'EUR')->compact());
        $this->assertSame('-$100.00', Money::of(-100, 'USD')->format());
        $this->assertSame(
            '($100.00)',
            Money::of(-100, 'USD')
                ->setNegativeStyle('parentheses')
                ->format(),
        );
        $this->assertSame('XYZ 5', (string) Money::of(5, 'XYZ'));
    }

    public function test_arithmetic_keeps_the_currency(): void
    {
        $money = Money::of(100, 'GHS')
            ->add(Money::of(50, 'GHS'))
            ->subtract('30')
            ->multiply(2)
            ->divide(4);

        $this->assertSame(60.0, $money->value());
        $this->assertSame('GHS', $money->currencyCode());
        $this->assertSame(100.0, Money::of(99.45)->ceil()->value());
        $this->assertSame(99.0, Money::of(99.95)->floor()->value());
        $this->assertSame(99.46, Money::of(99.456)->round(2)->value());
        $this->assertSame(10.0, Money::of(100)->mod(30)->value());
        $this->assertSame(100.0, Money::of(-100)->absolute()->value());
        $this->assertSame(5.0, Money::of(100)->share(10, 0.5)->value());

        $this->expectException(DivisionByZeroException::class);
        Money::of(1)->divide(0);
    }

    public function test_exchange_rate_api_converts_and_reports_errors(): void
    {
        $http = $this->http(['result' => 'success', 'conversion_rate' => 1500.5, 'conversion_result' => 150050]);

        Exchange::setApiKey('key-1');
        Exchange::setHttpClient($http);

        $converted = Exchange::from('usd')
            ->to('NGN')
            ->convert(100);

        $this->assertSame(150050.0, $converted->value());
        $this->assertSame('NGN', $converted->currencyCode());
        $this->assertSame('https://v6.exchangerate-api.com/v6/key-1/pair/USD/NGN/100', $http->urls[0]);
        $this->assertSame(1500.5, Exchange::to('NGN')->from('USD')->rate());

        $failing = new ExchangeRateApi('key-1', $this->http(['result' => 'error', 'error-type' => 'unsupported-code']));

        try {
            $failing->rate('USD', 'ABC');
            $this->fail('No exception thrown.');
        } catch (ExchangeException $e) {
            $this->assertSame('unsupported-code', $e->type);
        }
    }

    public function test_frankfurter_needs_no_key_and_same_currency_skips_the_provider(): void
    {
        $http = $this->http(['rate' => 0.9]);

        Exchange::setProvider(FrankfurterApi::class);
        Exchange::setHttpClient($http);

        $this->assertSame('€90.00', Exchange::format(100, 'USD', 'EUR'));
        $this->assertSame(1.0, Exchange::from('EUR')->to('eur')->rate());
        $this->assertCount(1, $http->urls);
    }

    public function test_missing_currencies_throw(): void
    {
        $this->expectException(ExchangeException::class);
        $this->expectExceptionMessage('Target currency not specified');

        Exchange::from('USD')->rate();
    }
}
