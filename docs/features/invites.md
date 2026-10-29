# Invites

Every user has an invite link. When someone joins with it and later pays for anything (Premium, AI credits, a release, stems or a tip), the inviter earns a part of what the platform keeps from that payment.

## How it works

1. A user's code is made from their username the first time they open **Settings > Invites**. They can change it there. Codes are lowercase letters, numbers, `-` and `_`.
2. The link is `/join/<code>`, which opens sign-up with the inviter shown. Sign-up sends the code as `referral_code`, and the API refuses unknown codes.
3. Who invited whom (`users.referred_by_id`, `users.referred_at`) is set once, at sign-up.
4. When an invited person's payment completes, the inviter earns `loyalty_rate` percent of the platform's share: the amount minus the creator's share. For Premium and AI credits that is the whole amount.
5. Payments only count for `loyalty_months` after sign-up (0 means no end).

Both settings are under **Configuration > Invites** (see [App configuration](../reference/configuration)).

## Money

Each reward is a `transactions` row with purpose `referral`, `amount` 0, the inviter as `recipient_id`, the reward in `recipient_share`, and the payment it came from as `transactable`. It counts toward the balance, clears after `payout_hold_days` and is withdrawn like any other earning (see [Payouts](./payouts)). The inviter gets a `sales` notification.

## In code

- `App\Services\Referrals\Referrals`: codes, `attach()` at sign-up, `terms()`, `reward()` (called from `Checkout::settle()`) and `summary()`.
- `App\Services\Referrals\ReferralTerms`: the rate and months an inviter earns on.
- Routes: `GET/PUT account/referrals`, `GET account/referrals/invites`, and the public `GET referrals/{code}`.
