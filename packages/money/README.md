# toneflix/money

Money formatting, arithmetic and currency conversion with live exchange rates. A PHP port of [@toneflix/money](https://www.npmjs.com/package/@toneflix/money) with the same names, so code reads the same on the API and in the app.

## Formatting

```php
use Toneflix\Money\Money;

Money::of(1234.56, 'USD')->format();   // "$1,234.56"
Money::of(1234.56, 'NGN')->whole();    // "₦1,235" (rounded)
Money::of(1234567, 'USD')->compact();  // "$1.2M"
Money::of(-100, 'USD')->setNegativeStyle('parentheses')->format(); // "($100.00)"
```

Unknown currency codes format as `"XYZ 100"`.

## Arithmetic

`add`, `subtract`, `multiply`, `divide`, `ceil`, `floor`, `round($digits)`, `mod`, `absolute` and `share($total, $ratio)` each return a new `Money` in the same currency. They take numbers, numeric strings or other `Money` objects. `share` is `floor(amount × ratio ÷ total)`, as in the JavaScript package.

## Conversion

```php
use Toneflix\Money\Exchange;
use Toneflix\Money\Providers\FrankfurterApi;

Exchange::setApiKey('your-exchangerate-api-key'); // or EXCHANGERATE_API_KEY in the environment

Exchange::from('USD')->to('NGN')->convert(100)->format(); // "₦152,300.00"
Exchange::from('USD')->to('NGN')->rate();                 // 1523.0
Money::of(100, 'USD')->convert('EUR');                    // Money in EUR

Exchange::setProvider(FrankfurterApi::class); // no key needed; ECB currencies only
```

Providers implement `Contracts\ExchangeRateProvider`. `Exchange::setProvider()` takes a class name (built with the API key and HTTP client) or an instance. Requests go through `Contracts\HttpClient`; the default uses PHP streams, and `Exchange::setHttpClient()` swaps it for your framework's client or a fake.

Failed lookups throw `Exceptions\ExchangeException` with the provider's error `type` (`invalid-key`, `quota-reached`, `unsupported-code`, ...).
