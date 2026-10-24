# Roles and permissions

Roles and permissions use [spatie/laravel-permission](https://spatie.be/docs/laravel-permission) and are listed in `config/permission-defs.php`. `php artisan app:sync-roles` creates them.

## Roles

`user`, `admin`, `support`, `manager` and `super-admin`. `admin` and `super-admin` count as elevated, which lets them see files other people own.

## Permissions

| Permission | Opens |
| --- | --- |
| `manage-configuration` | Configuration, Realtime, Sounds (and the Freesound library account), AI and Payouts > Providers |
| `manage-plans` | Plans |
| `manage-payouts` | Withdrawal requests |
| `manage-content` | Moderation (reports, takedowns, appeals) |
| `manage-users` | Users: the list, each user's page, editing, suspending, verifying and AI credits |
| `manage-admins` | With `manage-users`, changing roles and suspending other staff |

Any of these five opens the admin overview at `/admin`, which shows sign-ups, subscribers, releases and projects. Revenue shows with `manage-plans` or `manage-payouts`, withdrawals waiting with `manage-payouts`, and the moderation queue with `manage-content`. The numbers come from `GET /api/admin/stats`.

On a user's page, giving a plan also needs `manage-plans` and changing their balance also needs `manage-payouts`. See [Users](/features/users).

New features that add a permission need `php artisan app:sync-roles` after deploy before their admin pages open.

## Giving someone access

`app:sync-roles` gives each role every permission except the ones listed for it under `exclusions` in `config/permission-defs.php`. `super-admin` gets all of them, `admin` and `manager` get all but `manage-admins`, `support` gets neither `manage-admins` nor plans and payouts, and `user` gets none.

```bash
php artisan app:sync-roles 5 --roles=admin
php artisan app:sync-roles 5 --permissions=manage-content
php artisan app:sync-roles 5 --remove --roles=admin
```
