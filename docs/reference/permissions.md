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

`manage-users` and `manage-admins` also exist, but no page checks them yet.

New features that add a permission need `php artisan app:sync-roles` after deploy before their admin pages open.

## Giving someone access

`app:sync-roles` gives each role every permission except the ones listed for it under `exclusions` in `config/permission-defs.php`. `super-admin` gets all of them, and `admin` gets all but `manage-admins`.

```bash
php artisan app:sync-roles 5 --roles=admin
php artisan app:sync-roles 5 --permissions=manage-content
php artisan app:sync-roles 5 --remove --roles=admin
```
