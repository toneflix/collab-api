# Toneflix Music API

Toneflix Music is a community-driven music platform: where music meets community, creativity and collaboration. People listen, discover artists, make music together in the studio, write songs with a smart songwriting assistant, and release their work.

This repository is the Laravel API behind it: accounts, content, the studio, payments and payouts, moderation, notifications, queues, webhooks and the admin logic. The app people use lives in [toneflix/collab](https://github.com/toneflix/collab).

## Requirements

- PHP 8.3 with `pdo_pgsql`, `pgsql`, `gd`, `intl`, `zip`, `exif`, `bcmath`, `mbstring`, `curl` (and `gmp` for faster web push)
- Composer 2
- PostgreSQL 14 or newer
- FFmpeg built with Chromaprint ([guide](docs/guide/ffmpeg.md))

## Setup

```bash
git clone git@github.com:toneflix/collab-api.git
cd collab-api

cp .env.example .env
composer install
php artisan key:generate

createdb toneflix_collab
php artisan migrate --seed
php artisan app:sync-roles
php artisan app:sync-roles 1 --roles=admin
php artisan storage:link
```

Seeding creates the default plans, genres, app configuration and an admin user, `admin@toneflix.ng` / `password`.

## Run

Run each in its own terminal:

```bash
php artisan serve --port=8001   # the app points here by default
php artisan queue:work          # audio processing, AI jobs, notifications
php artisan schedule:work       # scheduled tasks
```

For realtime, set `REALTIME_DRIVER=reverb` and the `REVERB_*` keys, then run `php artisan reverb:start --port=8080`.

## Tests

```bash
createdb toneflix_collab_tests
DB_DATABASE=toneflix_collab_tests php artisan migrate --seed
php artisan test
```

## Documentation

The full guides for the API and the app (local setup, `.env` reference, production deploy, payments and every feature) are in [`docs/`](docs/), a VitePress site:

```bash
cd docs && yarn install && yarn dev
```

Start with [Overview](docs/guide/overview.md), [API: local setup](docs/guide/api-local.md) and [API .env](docs/reference/api-env.md).
