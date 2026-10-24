# Users

Admins with `manage-users` find accounts at `/admin/users` and open each one for its numbers and management actions.

## The list

`GET /api/admin/users` is paginated (20 a page, up to 50 with `limit`). It takes:

- `search`: name, username, email or id.
- `status`: `active`, `suspended`, `premium` (on a paid plan now), `verified` or `staff` (has a role other than `user`).
- `role`: one role name.
- `permission`: one permission name, held directly or through a role.
- `sort`: `latest` (default), `oldest` or `name`.

Admins see every account, including ones that blocked them. `App\Services\Admin\UserDirectory` builds this query, and `php artisan app:users` uses it too.

## A user's page

`GET /api/admin/users/{id}` returns the account, what the viewing admin `can` do to it, and stats from `App\Services\Admin\UserInsights`:

- Content: projects, collabs, releases, tracks, followers.
- Plan: the current plan and when paid time runs out.
- Earnings: the same summary creators see, from `Payouts::summary()`.
- Spend: completed payments the user made, by purpose.
- AI: the credit balance from `Credits`, credits used this month and all time, and AI jobs.
- Storage used against the plan's limit.

Two paginated lists sit under it: `GET .../transactions?side=payments|earnings` and `GET .../activity`, the audit trail.

## Actions

`App\Services\Admin\UserManager` makes every change and records it in `admin_actions` (admin, action, reason, details). It is also the one place that decides who may do what:

| Action | Endpoint | Needs, on top of `manage-users` |
| --- | --- | --- |
| Edit profile | `PUT /api/admin/users/{id}` | |
| Verify or remove the badge | `POST .../verify` | |
| Suspend for some days, or ban | `POST .../suspend`, `POST .../unsuspend` | `manage-admins` when the user is staff |
| Change roles | `PUT .../roles` | `manage-admins` |
| Give or remove a plan | `POST .../plan`, `DELETE .../plan` | `manage-plans` |
| Add to or take from the balance | `POST .../funds` | `manage-payouts` |
| Grant or take back AI credits | `POST .../credits` | |

Other rules:

- Admins can't suspend themselves or change their own roles.
- Only super admins act on super admins, or give and take the `super-admin` role.
- Roles and permissions changed with `php artisan app:user-access` go through the same service. They have no admin, so the history shows them as made from the console. See [Permissions](../reference/permissions#giving-someone-access).

### Suspensions

A suspended account is signed out everywhere (its tokens are deleted), can't sign in, and gets a 403 on any signed-in request until `suspended_until` passes. A ban is a suspension with no end date, lifted only by an admin.

### Plans

Giving a plan is free for the user. More time on the plan they already have is added after it; a different plan replaces it from now. Removing a plan ends the current period and any paid in advance, with no refund. Both go through `App\Services\Payment\Subscriptions`, which checkout uses too.

### Funds

A balance change is a `transactions` row with purpose `adjustment`, the user as recipient and the amount in `recipient_share` (negative to take). It is available at once, can't take more than is available, shows in the creator's earnings with the reason, and is left out of admin revenue.

### AI credits

Granted credits are added like bought ones and never expire. Taking credits back only takes bought or granted ones (`Credits::deduct()`), never the monthly plan allowance.
