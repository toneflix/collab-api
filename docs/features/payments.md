# Payments

People pay with Paystack's popup, or with Flutterwave: its checkout page (Standard) on the web and its popup (Inline) in the Android and iOS apps. When more than one provider is open to them, they pick one and see the charge in their currency first. The API starts the checkout, and the payment is confirmed by asking the provider directly: on the payment return page (`/payments/<reference>`), when the popup finishes, or when the provider's webhook arrives.

Admins choose which providers take payments, and can send groups of countries to their own providers and currency with payment regions; see [Payment providers](../guide/payments#payment-regions).

## What can be bought

| Purpose | What happens when paid |
| --- | --- |
| Paid release | The buyer can stream and download the whole release. |
| Stems | The buyer can download a released track's stems. |
| Tip | Money goes to an artist from their profile, with an optional message. |
| Plan | The subscription is extended by the billing option's cycles. |
| AI credits | The credit pack is added to the buyer's balance. |

Creators set prices in the studio. Selling releases and stems and receiving tips are plan features (`sell_releases`, `sell_stems`, `receive_tips`); see [Plans and features](./plans).

## Creator share

Each sale and tip records the creator's part in `recipient_share`. It is `creator_share` percent of the amount (85 by default). The rest stays with the platform. Earnings are on `/studio/earnings` and can be withdrawn; see [Payouts](./payouts).

`app_currency` (all prices use it) and `min_tip_amount` (100) also matter here. All three are in [App configuration](../reference/configuration).

## Pages

- `/premium`: plans and billing options
- `/library/purchases`: what someone has bought
- `/studio/earnings`: sales, tips and balance
- `/payments/<reference>`: where providers send payers back; it confirms the payment and returns them to the page they paid from

## In code

- `App\Services\Payment\Checkout` starts, verifies and settles every purchase. Each one is a `Transaction`.
- `App\Services\Payment\PaymentGateways` holds the providers (`PaystackGateway`, `FlutterwaveGateway`) and which are on.
- `App\Services\Payment\PaymentRegions` decides each payer's currency, rate and providers (`PaymentOptions`). `GET /api/payments/options` returns them to the app.
- `App\Services\Payment\Rates\CurrencyConverter` gets live rates through `toneflix/money`.
- The app uses `@toneflix/paystack-inline`, Flutterwave's `v3.js` in the app, and `PendingPayments` to confirm payments that left the app.

Setup and webhooks are in [Payment providers](../guide/payments).
