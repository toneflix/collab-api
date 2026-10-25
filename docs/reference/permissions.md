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
| `manage-content` | Moderation (reports, takedowns, appeals, artist claims) and editing or verifying any artist |
| `manage-users` | Users: the list, each user's page, editing, suspending, verifying and AI credits |
| `manage-admins` | With `manage-users`, changing roles and suspending other staff |

Any of these five opens the admin overview at `/admin`, which shows sign-ups, subscribers, releases and projects. Revenue shows with `manage-plans` or `manage-payouts`, withdrawals waiting with `manage-payouts`, and the moderation queue with `manage-content`. The numbers come from `GET /api/admin/stats`.

On a user's page, giving a plan also needs `manage-plans` and changing their balance also needs `manage-payouts`. See [Users](/features/users).

New features that add a permission need `php artisan app:sync-roles` after deploy before their admin pages open.

## Giving someone access

`app:sync-roles` gives each role every permission except the ones listed for it under `exclusions` in `config/permission-defs.php`. `super-admin` gets all of them, `admin` and `manager` get all but `manage-admins`, `support` gets neither `manage-admins` nor plans and payouts, and `user` gets none.

Use `app:user-access` to give or take roles and permissions one at a time. The user can be named by id, username or email, and roles and permissions by name or id. Each change is added to the user's admin history, marked as made from the console.

```bash
php artisan app:user-access jane                              # show her roles and permissions
php artisan app:user-access jane --grant-role=admin
php artisan app:user-access jane@example.com --revoke-role=admin --grant-role=support
php artisan app:user-access 5 --grant-permission=manage-content
php artisan app:user-access 5 --revoke-permission=manage-content
```

Permissions granted this way belong to the user directly, on top of the ones their roles give them. Only roles and permissions that `app:sync-roles` has created can be granted.

`app:sync-roles <user ids> --roles=<role>` still works, but it replaces all of a user's roles and permissions at once and is not recorded in their history.

To find people, `app:users` lists users a page at a time:

```bash
php artisan app:users                                # newest first, 20 a page
php artisan app:users --search=jane --per-page=50
php artisan app:users --role=admin
php artisan app:users --permission=manage-users      # directly or through a role
php artisan app:users --status=suspended --sort=oldest --page=3
```

`--status` takes `active`, `suspended`, `premium`, `verified` or `staff`, and `--sort` takes `latest`, `oldest` or `name`. When more pages follow, it asks whether to show the next one; pass `--no-interaction` to print one page only.
