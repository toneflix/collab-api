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

## Reports

Inviters and royals see what invites earned them on the **Invites** page:

- `GET account/referrals` includes `months`: earnings and payments for each of the last twelve months.
- `GET account/referrals/invites` lists each invitee with total `earned`, `payments` and `rewardsEndAt` (when their payments stop counting, empty when the terms have no end). With `month=YYYY-MM` it adds `monthEarned` and `monthPayments` and lists the month's biggest earners first.

## In code

- `App\Services\Referrals\Referrals`: codes, `attach()` at sign-up, `terms()`, `reward()` (called from `Checkout::settle()`) and `summary()`.
- `App\Services\Referrals\ReferralTerms`: the rate and months an inviter earns on.
- Routes: `GET/PUT account/referrals`, `GET account/referrals/invites`, and the public `GET referrals/{code}`.

## Royalty program

Royals are partners an admin invites on their own contract. A royal's invite link pays their contract rate instead of `loyalty_rate`.

1. An admin with `manage-payouts` opens **Payouts > Royalties** and enters an email, a name, the rate, the months each invited person's payments count (0 for no end), an optional invite code and a note. Leaving the code empty keeps the royal's own code, or makes one from their username.
2. The invite (`royalty_invites`) is emailed with a link to `/royalty/<token>`, and is open for 14 days. Admins can send it again, which restarts the 14 days, or cancel it. An open invite's code is reserved, so nobody else can take it.
3. Someone without an account signs up from the link with the invited email address; sign-up sends `royalty_token`. Someone with an account accepts while signed in (`POST royalty-invites/{token}/accept`). Either way it must be the email the invite was sent to.
4. Accepting creates a `royalty_contracts` row and gives the royal the invite's code.
5. Changing a rate or months ends the current contract and starts a new one, so each reward shows the terms that paid it (`meta.contract_id`). Ending a contract sends the royal back to loyalty terms.

Rewards are the same `referral` transactions, with `method` and `meta.program` set to `royalty`. Each invite, acceptance, change and ending is written to `admin_actions` for the royal's account.

`App\Services\Referrals\Royalties` makes every change. Routes: `admin/royalties/invites` (list, create, resend, cancel), `admin/royalties/contracts` (list with invited count and earnings, update, end), and the public `GET royalty-invites/{token}`.
