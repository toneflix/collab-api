# Commands and schedule

## Scheduled tasks

These run from `php artisan schedule:run`, which cron calls every minute (see [Production deploy](../guide/production#scheduler)). They are defined in `routes/console.php`.

| Command | When | What it does |
| --- | --- | --- |
| `app:recommend` | hourly | Refreshes expired recommendations, up to 1000 users per run. |
| `realtime:prune` | hourly | Deletes realtime events older than an hour from Firebase. Does nothing on other drivers. |
| `sounds:sync` | daily, 03:30 | Adds CC0 sounds from Freesound to the shared library. Skipped while the library is off or the sync is paused. |
| `studio:prune-history` | daily, 04:00 | Deletes track versions older than the owner's plan keeps, and stems only those versions used. Also deletes autosaved Composer song versions past the writer's `history_days`; named versions stay. |
| `payouts:sync` | every 30 minutes | Checks payouts that have been processing for over 10 minutes with their provider, in case a webhook never came. |
| `ai:sweep` | every 10 minutes | Refunds AI jobs that stopped without finishing. |
| `agent:tick` | every minute | Starts the next queued content agent run and gives the active one a pass. |

## Commands you run

| Command | When |
| --- | --- |
| `php artisan migrate --force` | Every deploy. |
| `php artisan app:sync-roles` | Every deploy. Creates roles and permissions that do not exist yet. |
| `php artisan app:users` | To list users a page at a time. Filter with `--search`, `--role`, `--permission` and `--status`. See [Permissions](./permissions#giving-someone-access). |
| `php artisan app:user-access <user> --grant-role=<role>` | To give someone a role. `--revoke-role`, `--grant-permission` and `--revoke-permission` work the same way. With no options it shows their access. Changes are kept in the user's admin history. |
| `php artisan ai:jobs` | To list AI jobs a page at a time with what went wrong. Filter with `--status`, `--user` and `--failures`. See [AI tools](../features/ai#when-a-job-fails). |
| `php artisan ai:video-trial <track>` | To compare music video models on one song before choosing defaults. Renders an 8 second window (from the first sung line, or `--from`) through each lip-sync and b-roll model with the song under it, after showing the cost. Pick models with `--only`, the still with `--image`, the shape with `--ratio`. Clips are saved under `ai/video-trials` and listed with week-long links. |
| `php artisan webpush:vapid` | Once, to create the browser push keys. |
| `php artisan words:import` | Once after the Composer migration (`--if-empty` skips it when loaded; the container start script runs that), and after replacing the dictionary file. Loads the bundled CMU dictionary. See [Composer](../features/composer#the-local-dictionary). |
| `php artisan storage:link` | Once, with the local disk. |
| `php artisan queue:restart` | After every deploy and `.env` change, so workers load the new code. |
| `php artisan reverb:restart` | After a deploy, when Reverb runs on the server. |
| `php artisan cache:forget audio::chromaprint` | After installing or changing FFmpeg. |
| `php artisan tracks:durations` | Once, to measure released tracks that show 0:00. `--all` measures every track again. New tracks are measured when their audio is saved. |
| `php artisan files:meta` | Once, to save the size and mime type of files and stems uploaded before they were stored. New uploads save them when written, so file sizes never ask the bucket. |

## Run a scheduled task by hand

Each scheduled command also works on its own:

```bash
php artisan app:recommend 12 34          # only these users
php artisan sounds:sync --force          # even with the library off
php artisan payouts:sync --older-than=0  # every processing payout
php artisan ai:sweep --older-than=40
php artisan realtime:prune --age=3600
```
