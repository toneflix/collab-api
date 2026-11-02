# API: local setup

## Requirements

- PHP 8.3 with these extensions: `pdo_pgsql`, `pgsql`, `gd`, `intl`, `zip`, `exif`, `bcmath`, `mbstring`, `curl`. Add `gmp` too; web push encryption is much faster with it.
- Composer 2
- PostgreSQL 14 or newer (CI uses 16). MySQL/MariaDB and SQLite also work, but PostgreSQL is the one we test on.
- FFmpeg built with Chromaprint. See [FFmpeg and Chromaprint](./ffmpeg).

## Install

```bash
git clone git@github.com:toneflix/collab-api.git
cd collab-api

cp .env.example .env
composer install
php artisan key:generate
```

## Database

```bash
createdb toneflix_collab
php artisan migrate --seed
php artisan app:sync-roles
```

`migrate --seed` creates the tables, the default Free and Premium plans, genres and the admin configuration. It also creates a user `admin@toneflix.ng` with the password `password`.

`app:sync-roles` creates the roles and permissions. Give the seeded user the admin role so the admin pages open:

```bash
php artisan app:sync-roles 1 --roles=admin
```

## Run it

Use three terminals:

```bash
php artisan serve --port=8001   # the app's .env points at http://127.0.0.1:8001 by default
php artisan queue:work          # audio processing, AI jobs, notifications
php artisan schedule:work       # the scheduled tasks, every minute
```

Add a fourth if you want realtime with Reverb:

```bash
php artisan reverb:start --port=8080
```

Set `REALTIME_DRIVER=reverb` and the `REVERB_*` keys in `.env` first. For local work, `REVERB_HOST=localhost`, `REVERB_PORT=8080` and `REVERB_SCHEME=http` are enough, with any values for the app id, key and secret. See [Realtime](../features/realtime).

## Local file storage

With `FILESYSTEM_DISK=local`, uploads go to `storage/app/public` (`avatars/`, `artwork/` and `media/`), which `public/` links to. Run `php artisan storage:link` once so those files are served.

Seeded tracks have no audio. To test playback, put mp3 files in `storage/app/public/media/music/tracks/` and set the `audio` column of a few `tracks` rows to their file names.

To keep uploads on S3 or an S3-compatible store (R2, MinIO, Spaces), set `FILESYSTEM_DISK=s3` and the `AWS_*` keys. See [API .env](../reference/api-env#storage).

## Mail

`MAIL_MAILER=log` writes every email to `storage/logs/laravel.log`, which is enough for local work.

## Tests

The tests use a separate database (see `phpunit.xml`) and keep data in transactions, so seed it once:

```bash
createdb toneflix_collab_tests
DB_DATABASE=toneflix_collab_tests php artisan migrate --seed
php artisan test
```

The S3 storage tests run against any S3 API. They are skipped unless `S3_TEST_ENDPOINT` is set, for example with [moto](https://github.com/getmoto/moto):

```bash
docker run -d -p 9090:5000 motoserver/moto:5.2.4
S3_TEST_ENDPOINT=http://127.0.0.1:9090 php artisan test --filter S3StorageTest
```

CI runs the same steps, S3 tests included, on every pull request (`.github/workflows/tests.yml`).
