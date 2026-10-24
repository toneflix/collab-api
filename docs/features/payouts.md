# Payouts

Creators withdraw their earnings to a bank account, a mobile money wallet or a Stripe account. The provider depends on the creator's country.

## How a provider is picked

Providers are tried in this order. The first one that is switched on and serves the creator's country pays them:

1. **Paystack**: banks (and mobile money in Ghana and Kenya) in the country of your Paystack business, which follows the [app currency](../guide/payments#app-currency).
2. **Flutterwave**: banks and mobile money in Nigeria, Ghana, Kenya, Uganda, Tanzania, Rwanda, Zambia, South Africa, Cameroon, Côte d'Ivoire and Senegal.
3. **Stripe Connect**: the US, Canada, the UK, most of the EU and other Stripe countries. Creators finish Stripe's hosted onboarding first, and their account stays pending until they do.

Switch providers on under **Payouts > Providers** (needs `manage-configuration`). Keys and webhooks are in [Payment providers](../guide/payments).

## A withdrawal's life

1. Earnings clear after `payout_hold_days` (7 by default). Only cleared earnings can be withdrawn.
2. The creator asks for at least `min_payout_amount` (1000 by default).
3. An admin with `manage-payouts` approves or declines it on `/admin/payouts`, unless `payout_auto_approve` is on. These are in [App configuration](../reference/configuration).
4. The API sends the transfer. When the payout currency differs from the app currency, the exchange rate is fixed at this point and saved with the payout.
5. The provider's webhook, or `payouts:sync` every 30 minutes, marks it paid or failed. Failed payouts go back to the balance.

The creator is notified at each step (the `payouts` notification category).

## Data

- `payout_accounts`: provider, country, method (`bank`, `mobile_money` or `hosted`) and status (`active` or `pending`).
- `payouts`: the amount in the app currency, plus `payout_amount`, `payout_currency` and `exchange_rate`.

## In code

- `App\Services\Payment\Payouts` owns the balance, the payout account and each payout's lifecycle.
- `PayoutProviders` holds the gateways in order. Each implements `PayoutGateway`, plus `CollectsBankDetails` (Paystack, Flutterwave) or `HostsPayoutAccounts` (Stripe).
- `PayoutSettings` holds the on/off switches and the Stripe country, currency and rate.
