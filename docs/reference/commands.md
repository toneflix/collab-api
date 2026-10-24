# Commands and schedule

## Scheduled tasks

These run from `php artisan schedule:run`, which cron calls every minute (see [Production deploy](../guide/production#scheduler)). They are defined in `routes/console.php`.

| Command | When | What it does |
| --- | --- | --- |
| `app:recommend` | hourly | Refreshes expired recommendations, up to 1000 users per run. |
| `realtime:prune` | hourly | Deletes realtime events older than an hour from Firebase. Does nothing on other drivers. |
| `sounds:sync` | daily, 03:30 | Adds CC0 sounds from Freesound to the shared library. Skipped while the library is off. |
| `studio:prune-history` | daily, 04:00 | Deletes track versions older than the owner's plan keeps, and stems only those versions used. |
| `payouts:sync` | every 30 minutes | Checks payouts that have been processing for over 10 minutes with their provider, in case a webhook never came. |
| `ai:sweep` | every 10 minutes | Refunds AI jobs that stopped without finishing. |

## Commands you run

| Command | When |
| --- | --- |
| `php artisan migrate --force` | Every deploy. |
| `php artisan app:sync-roles` | Every deploy. Creates roles and permissions that do not exist yet. |
| `php artisan app:sync-roles <user ids> --roles=<role>` | To give people roles, for example `--roles=admin`. Add `--remove` to take them away. `--permissions=<name>` works the same way for single permissions. |
| `php artisan webpush:vapid` | Once, to create the browser push keys. |
| `php artisan storage:link` | Once, with the local disk. |
| `php artisan queue:restart` | After every deploy and `.env` change, so workers load the new code. |
| `php artisan reverb:restart` | After a deploy, when Reverb runs on the server. |
| `php artisan cache:forget audio::chromaprint` | After installing or changing FFmpeg. |

## Run a scheduled task by hand

Each scheduled command also works on its own:

```bash
php artisan app:recommend 12 34          # only these users
php artisan sounds:sync --force          # even with the library off
php artisan payouts:sync --older-than=0  # every processing payout
php artisan ai:sweep --older-than=40
php artisan realtime:prune --age=3600
```
