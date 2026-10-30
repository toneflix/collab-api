# Payment providers

Money comes **in** through Paystack or Flutterwave (paid releases, stems, tips, plans and AI credit packs). Money goes **out** to creators through Paystack, Flutterwave or Stripe Connect, depending on the creator's country. How the features work is in [Payments](../features/payments) and [Payouts](../features/payouts). This page is what to set up with each provider.

All keys go in the API's `.env`. The admin pages under **Payouts > Providers** only switch providers on and off, for payments and payouts separately, and show which keys are missing.

## App currency

Prices, balances and payouts use one app currency, the `app_currency` setting in [App configuration](../reference/configuration). New installs start with `NGN`.

Paystack only pays out in the country of your Paystack business, and the API works that out from the app currency:

| App currency | Paystack payout country |
| --- | --- |
| NGN | Nigeria |
| GHS | Ghana |
| KES | Kenya |
| ZAR | South Africa |

With any other app currency, Paystack payouts are off and creators need Flutterwave or Stripe. Paystack must also be able to charge in the app currency.

## Payment regions

Under **Payouts > Payment regions**, admins group countries into regions. A payer in a region pays through that region's providers (first one is the default) and is charged in its currency. Everyone else pays in the app currency through every provider that is switched on. A region whose providers are all off falls back to that too.

The payer's country is their payout account's country, then the country on their profile, then the `CF-IPCountry` header when the API sits behind Cloudflare.

Prices stay in the app currency. At checkout the price is converted at the region's **fixed rate** (units of the region currency per unit of the app currency) or, when that is empty, at the live rate plus the region's **markup**. The charge is rounded up to the cent, and the transaction keeps the app-currency amount, the charge, its currency and the rate. A payment is only accepted when the provider reports at least the charge, in that currency.

Live rates come from [toneflix/money](https://github.com/toneflix/money-php), the PHP port of `@toneflix/money`, and are cached for 6 hours:

```dotenv
# exchangerate-api (needs a key, covers NGN, GHS, KES and the rest) or frankfurter (no key, ECB currencies only)
EXCHANGE_RATE_PROVIDER=exchangerate-api
EXCHANGERATE_API_KEY=
```

## Payment return page

Paystack's `callback_url` and Flutterwave's `redirect_url` are `FRONTEND_URL/payments/<reference>`. That page confirms the payment and sends the payer back to the page they paid from. In the Android and iOS apps the same link opens the app (App Links and Universal Links, see [Android](./android#app-links)), so a payment finished in a browser or a bank app still lands back in the app, even if the system closed it.

## Paystack

```dotenv
PAYSTACK_PUBLIC_KEY=pk_live_...
PAYSTACK_SECRET_KEY=sk_live_...
```

The secret key must start with `sk_`. The app gets the public key from the API, so it needs no Paystack setting of its own.

### Webhook

In the Paystack dashboard, under **Settings > API Keys & Webhooks**, set the webhook URL to:

```text
https://<api>/api/payments/paystack/webhook
```

The API checks the `x-paystack-signature` header against the secret key. It handles:

- `charge.success`, which completes a checkout.
- Every `transfer.*` event (`transfer.success`, `transfer.failed`, `transfer.reversed`), which settles a payout.

The API never trusts the webhook body. It looks the transaction or transfer up with Paystack before changing anything, and `payouts:sync` catches anything a missed webhook left behind.

### Turn off transfer OTP

By default Paystack asks for an OTP before each transfer, which stops automatic payouts: transfers sit in the `otp` state until someone confirms them by hand. Turn it off under **Settings > Preferences > Transfers** ("Confirm transfers before sending"). Paystack asks for an OTP sent to the business owner to make this change.

## Flutterwave

Takes payments with Flutterwave Standard (its hosted checkout page) on the web and Flutterwave Inline (its popup) in the app. Pays banks and mobile money in Nigeria, Ghana, Kenya, Uganda, Tanzania, Rwanda, Zambia, South Africa, Cameroon, Côte d'Ivoire and Senegal.

```dotenv
FLUTTERWAVE_PUBLIC_KEY=FLWPUBK-...
FLUTTERWAVE_SECRET_KEY=FLWSECK-...
FLUTTERWAVE_WEBHOOK_HASH=a-long-random-string
```

In the Flutterwave dashboard, under **Settings > Webhooks**:

- URL: `https://<api>/api/payments/flutterwave/webhook` (the older `/api/payouts/flutterwave/webhook` takes the same events)
- Secret hash: the same value as `FLUTTERWAVE_WEBHOOK_HASH`. The API compares it with the `verif-hash` header.
- Turn on charge and transfer webhooks.

`charge.completed` completes a checkout and transfer events settle payouts; each is looked up with Flutterwave first. Then switch on **Payments** and **Flutterwave Payouts** under Payouts > Providers.

## Stripe Connect

Pays creators in the US, Canada, the UK, most of the EU, Switzerland, the Nordics, Poland, Australia, New Zealand, Singapore, Hong Kong, Japan, the UAE and Mexico. Creators finish Stripe's Express onboarding before they can withdraw.

```dotenv
STRIPE_SECRET_KEY=sk_live_...
# Optional, a separate key for Connect. STRIPE_SECRET_KEY is used when empty.
STRIPE_CONNECT_SECRET_KEY=
STRIPE_WEBHOOK_SECRET=whsec_...
```

### Account country, currency and rate

Under Payouts > Providers, set:

- **Stripe Account Country**: the two-letter country of your Stripe platform account. It has to be a country Stripe supports for platforms. Nigeria is not one, so a Nigerian business needs a Stripe account registered elsewhere (for example a US entity).
- **Stripe Balance Currency**: the currency transfers are sent in, usually the account's currency.
- **Stripe Exchange Rate**: how much of the Stripe currency one unit of the app currency is worth, for example `0.00065` for NGN to USD. You can leave it empty only when both currencies are the same. A payout's rate is fixed when it is first sent.

Creators in a different country from the platform are created as recipient accounts (`service_agreement: recipient`). Stripe only allows these from some platform countries, so check [Stripe's cross-border payouts](https://docs.stripe.com/connect/cross-border-payouts) for yours.

### Webhook

In Stripe, under **Developers > Webhooks**, add an endpoint:

- URL: `https://<api>/api/payouts/stripe/webhook`
- Events: `transfer.created`, `transfer.updated`, `transfer.reversed`
- Copy the signing secret into `STRIPE_WEBHOOK_SECRET`.

Then switch on **Stripe Connect Payouts** under Payouts > Providers.

## Testing

Use test keys (`sk_test_...`) locally. Providers cannot reach `localhost`, so either use a tunnel (for example `cloudflared tunnel` or `ngrok`) for the webhook URLs, or rely on `php artisan payouts:sync`, which asks each provider for the status of payouts that are still processing.
