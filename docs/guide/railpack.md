# Railpack and Railway

The API ships a `railpack.json` and a `start-container.sh`, so [Railpack](https://railpack.com) (what Railway builds with) produces an image with everything the API needs. One image runs the web server, the queue worker, the scheduler and Reverb.

## What the image contains

Railpack builds on the FrankenPHP image for the PHP version in `composer.json` (8.3) and serves `public/` on `$PORT`.

| What | Why |
| --- | --- |
| `ffmpeg` (Debian, built with Chromaprint) | Encoding uploads and copied-audio fingerprints. See [FFmpeg and Chromaprint](./ffmpeg). |
| `pdo_pgsql`, `pgsql` | PostgreSQL |
| `gd`, `exif` | Image uploads (avatars, artwork) |
| `intl`, `bcmath`, `zip` | Laravel and package requirements |
| `gmp` | Fast web push encryption |
| `pcntl` | Lets `queue:work --timeout` stop stuck jobs |

Laravel's own required extensions are added by Railpack.

`AUDIO_ENCODING_CODEC` defaults to `aac` in the image because Debian's FFmpeg has no `libfdk_aac`. To add an apt package, list it under `deploy.aptPackages`. To add a PHP extension, add it to the `install-php-extensions` line in `railpack.json`.

## Processes

`start-container.sh` reads `CONTAINER_ROLE` and runs one process. On Railway, create one service per role from the same repository and set `CONTAINER_ROLE` on each.

| `CONTAINER_ROLE` | Runs | Notes |
| --- | --- | --- |
| `web` (default) | FrankenPHP | Runs `migrate --force`, `app:sync-roles` and `words:import --if-empty` first, unless `RAILPACK_SKIP_MIGRATIONS=true`. |
| `worker` | `queue:work --tries=3 --timeout=3600` | Needed for audio, fingerprints, AI jobs, mail and push. Scale it with replicas. |
| `scheduler` | `schedule:work` | Run exactly one. |
| `reverb` | `reverb:start` on `$PORT` | Only when `REALTIME_DRIVER=reverb`. |

Every role clears and rebuilds Laravel's caches on start, so changed variables apply after a restart.

## Variables

Set the same variables on every service (Railway's shared variables help). See [API .env](../reference/api-env) for the full list. At least:

```dotenv
APP_KEY=base64:...
APP_URL=https://collab-api.example.com
FRONTEND_URL=https://collab.example.com
DB_CONNECTION=pgsql
DB_URL=${{Postgres.DATABASE_URL}}
QUEUE_CONNECTION=database
FILESYSTEM_DISK=s3
```

Container disks are wiped on every deploy, and the worker runs in its own container, so keep uploads in S3 or R2 (see [Production deploy](./production#file-storage)) rather than on the local disk.

For web push keys, run `php artisan webpush:vapid` locally and copy `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY` from your `.env`.

For Reverb, give the `reverb` service a public domain and point the API at it:

```dotenv
REALTIME_DRIVER=reverb
REVERB_HOST=<reverb service domain>
REVERB_PORT=443
REVERB_SCHEME=https
```

## Build it locally

```bash
railpack build . --name collab-api
docker run --rm -p 8000:80 --env-file .env collab-api
docker run --rm --env-file .env -e CONTAINER_ROLE=worker collab-api
```

`railpack info .` prints the build plan without building.
