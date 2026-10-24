# Payments

People pay through Paystack's inline checkout, which opens over the app. The API starts the checkout, and the payment is confirmed by asking Paystack directly, either when the app returns or when the `charge.success` webhook arrives.

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

## In code

- `App\Services\Payment\Checkout` starts, verifies and settles every purchase. Each one is a `Transaction`.
- `App\Services\Payment\PaystackGateway` talks to Paystack through `toneflix/paystack-php`.
- The app uses `@toneflix/paystack-inline`.

Setup and webhooks are in [Payment providers](../guide/payments).
