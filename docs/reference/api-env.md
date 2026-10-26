# API .env

Every key and secret the API uses comes from `.env`, read through files in `config/`. Admin pages show whether keys are set, but you change them here. After editing `.env` in production, run `php artisan config:cache` and `php artisan queue:restart`.

`.env.example` in the repository has every key below with its default.

## App

| Key | Default | What it does |
| --- | --- | --- |
| `APP_NAME` | `Laravel` | Name in emails and notifications. |
| `APP_ENV` | `local` | `production` on live servers. |
| `APP_KEY` | | Encryption key. Create it with `php artisan key:generate`. |
| `APP_DEBUG` | `true` | Must be `false` in production. |
| `APP_URL` | `http://localhost` | The API's public address. File and placeholder image links are built from it. |
| `TRUSTED_PROXIES` | `*` | Proxies whose `X-Forwarded-*` headers are trusted, as a comma-separated list of addresses. Keep `*` behind Railway or another TLS proxy. |
| `ASSET_URL` | `APP_URL` | Where placeholder images in `public/defaults` are served from, if not `APP_URL`. |
| `FRONTEND_URL` | `https://collab.toneflix.ng` | The app's public address. Used for links in emails and push, push icons, and the Freesound callback. |
| `APP_TIMEZONE` | `UTC` | |
| `LOG_LEVEL` | `debug` | Use `error` or `warning` in production. |

## Database

PostgreSQL is the one we test on. MySQL/MariaDB and SQLite also work.

| Key | Default |
| --- | --- |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST` | `127.0.0.1` |
| `DB_PORT` | `5432` |
| `DB_DATABASE` | `toneflix_collab` |
| `DB_USERNAME` | `postgres` |
| `DB_PASSWORD` | |

## Queue, cache and sessions

| Key | Default | What it does |
| --- | --- | --- |
| `QUEUE_CONNECTION` | `database` | `database` or `redis`. Never `sync` in production; AI jobs and the sound sync would run inside web requests. |
| `DB_QUEUE_RETRY_AFTER` | `3700` | Seconds before a job that is still running is handed to another worker. Keep it above the longest job. See [Production deploy](../guide/production#processes-that-must-run). |
| `REDIS_QUEUE_RETRY_AFTER` | `3700` | The same, for the Redis queue. |
| `CACHE_STORE` | `database` | |
| `SESSION_DRIVER` | `database` | |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | | Only when Redis is used. |

## Storage

| Key | Default | What it does |
| --- | --- | --- |
| `FILESYSTEM_DISK` | `local` | `local`, or `s3` for Amazon S3 and S3-compatible stores (R2, MinIO, Spaces). |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | | Bucket credentials. |
| `AWS_DEFAULT_REGION` | `us-east-1` | |
| `AWS_BUCKET` | | |
| `AWS_ENDPOINT` | | For S3-compatible stores, for example `https://<account>.r2.cloudflarestorage.com`. |
| `AWS_URL` | | Public address of the bucket, when it differs from the default. |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `false` | `true` for MinIO. |
| `FILEABLE_TEMPORARY_URLS` | `true` | Sign file links, for private buckets. `false` gives plain bucket URLs. |
| `FILEABLE_TEMPORARY_URL_TTL` | `720` | Minutes a signed link lasts. Pages and play queues hold links for a while, so keep it in hours. |

The bucket needs a CORS rule so the studio can load stems. See [File storage](../guide/production#file-storage).

## Audio

| Key | Default | What it does |
| --- | --- | --- |
| `FFMPEG_BINARIES` | `ffmpeg` | Path to FFmpeg. It needs Chromaprint for copied-audio detection. |
| `FFPROBE_BINARIES` | `ffprobe` | Path to ffprobe. |
| `FFMPEG_TEMPORARY_FILES_ROOT` | system temp | Scratch folder for encoding. |
| `AUDIO_ENCODING_CODEC` | empty (`libfdk_aac`) | Set `aac` when FFmpeg has no `libfdk_aac`, like Ubuntu's package. |

See [FFmpeg and Chromaprint](../guide/ffmpeg).

## Mail

Standard Laravel mail settings: `MAIL_MAILER` (`log` locally, `smtp` or another mailer in production), `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`.

## Realtime

The driver is chosen on the admin Realtime page, which starts from `REALTIME_DRIVER`. Only the chosen driver's keys are needed. See [Realtime](../features/realtime).

| Key | Default | What it does |
| --- | --- | --- |
| `REALTIME_DRIVER` | `none` | `none`, `reverb`, `pusher`, `ably` or `firebase`. |
| `BROADCAST_CONNECTION` | `log` | Laravel's default connection. The realtime driver replaces it at runtime, so leave it. |

**Reverb**

| Key | Default | What it does |
| --- | --- | --- |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | | Any values you choose. The key is sent to browsers; the secret stays on the server. |
| `REVERB_HOST` | `localhost` | Public host of the Reverb server. Used by the API to send events and by browsers to connect. |
| `REVERB_PORT` | `8080` | Public port, `443` behind TLS. |
| `REVERB_SCHEME` | `http` | `https` behind TLS. |
| `REVERB_SERVER_HOST` | `0.0.0.0` | Address `reverb:start` listens on. |
| `REVERB_SERVER_PORT` | `8080` | Port `reverb:start` listens on. |

**Pusher**

`PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET`, `PUSHER_APP_CLUSTER` (default `mt1`). `PUSHER_HOST`, `PUSHER_PORT` and `PUSHER_SCHEME` are optional overrides.

**Ably**

`ABLY_KEY`, the full API key (`appId.keyId:secret`). Ably is used through its Pusher protocol.

**Firebase Realtime Database**

| Key | What it does |
| --- | --- |
| `FIREBASE_CREDENTIALS` | The service account, as a path to its JSON file or the JSON itself. The API uses it to write events. |
| `FIREBASE_DATABASE_URL` | `https://<project>-default-rtdb.firebaseio.com` |
| `FIREBASE_API_KEY` | Web API key, sent to browsers. |
| `FIREBASE_PROJECT_ID` | Project id, sent to browsers. |
| `FIREBASE_APP_ID` | Optional web app id. |

## Payments and payouts

See [Payment providers](../guide/payments) for webhooks and dashboard settings.

| Key | What it does |
| --- | --- |
| `PAYSTACK_PUBLIC_KEY` | `pk_...`. Sent to the app for checkout. |
| `PAYSTACK_SECRET_KEY` | `sk_...`. Charges, transfers and webhook signatures. |
| `FLUTTERWAVE_SECRET_KEY` | Flutterwave payouts. |
| `FLUTTERWAVE_WEBHOOK_HASH` | The secret hash set on Flutterwave's webhook page. |
| `STRIPE_SECRET_KEY` | Stripe Connect payouts. |
| `STRIPE_CONNECT_SECRET_KEY` | Optional separate key for Connect. `STRIPE_SECRET_KEY` is used when empty. |
| `STRIPE_WEBHOOK_SECRET` | `whsec_...`, the endpoint's signing secret. |

## Sounds

| Key | Default | What it does |
| --- | --- | --- |
| `FREESOUND_CLIENT_ID` | | From your API app at [freesound.org/apiv2/apply](https://freesound.org/apiv2/apply). |
| `FREESOUND_CLIENT_SECRET` | | The app's secret, which is also its API key. |
| `FREESOUND_DOWNLOAD_DELAY` | `2` | Seconds between library downloads, to stay under Freesound's limits. |
| `FREESOUND_BASE_URL` | `https://freesound.org/apiv2` | Only for testing against a stand-in API. |

## Composer

| Key | Default | What it does |
| --- | --- | --- |
| `DATAMUSE_API_KEY` | | Optional until January 2027, when Datamuse starts requiring a key. Sent with every Datamuse request when set. |
| `DATAMUSE_BASE_URL` | `https://api.datamuse.com` | Only for testing against a stand-in API. |

## AI

| Key | Default | What it does |
| --- | --- | --- |
| `REPLICATE_API_TOKEN` | | From [replicate.com/account/api-tokens](https://replicate.com/account/api-tokens). AI tools stay off without it. |
| `REPLICATE_MUSIC_MODEL` | `elevenlabs/music` | Music model to start with, until an admin picks one on `/admin/ai`. |
| `REPLICATE_STEMS_MODEL` | `ryan5453/demucs:5a7041cc…` | Model for stem separation, pinned to Demucs' 22.04 release. The newer v1.0.0 takes different inputs and was slow to start. |
| `REPLICATE_STEMS_VARIANT` | `htdemucs` | Demucs weights. `htdemucs_ft` gives cleaner parts but takes about four times as long. |
| `REPLICATE_STEMS_TIMEOUT` | `1200` | Longest wait for a Demucs split, in seconds. Covers Replicate's queue and cold boots. |
| `REPLICATE_LYRICS_MODEL` | `openai/whisper` | Model for lyrics transcription. |
| `REPLICATE_MODERATOR_MODEL` | `anthropic/claude-4.5-haiku` | Model behind the AI moderator. A larger Claude model judges harder cases better at a higher cost per review. |
| `REPLICATE_COMPOSER_MODEL` | `anthropic/claude-4.5-haiku` | Model that suggests lyrics in Composer, until an admin picks one on `/admin/ai`. |
| `REPLICATE_ARTWORK_MODEL` | `black-forest-labs/flux-schnell` | Image model that draws AI artwork. It must take `aspect_ratio` and `num_outputs`. |
| `REPLICATE_TIMEOUT` | `600` | Seconds to wait for one prediction. |
| `SUNO_API_KEY` | | From [sunoapi.org/api-key](https://sunoapi.org/api-key). Needed only when admins pick Suno for music or stems. |
| `SUNO_URL` | `https://api.sunoapi.org` | Suno API base URL. |
| `SUNO_UPLOAD_URL` | `https://sunoapiorg.redpandaai.co` | sunoapi.org's file host, for audio Suno needs to read. |
| `SUNO_TIMEOUT` | `600` | Seconds to wait for one Suno task. |
| `CLOUDFLARE_ACCOUNT_ID` | | Cloudflare account that runs Workers AI. Needed only when admins pick Workers AI for a tool. |
| `CLOUDFLARE_API_TOKEN` | | A Cloudflare API token with the Workers AI permission. |
| `CLOUDFLARE_AI_URL` | `https://api.cloudflare.com/client/v4` | Cloudflare API base URL. |
| `CLOUDFLARE_AI_TIMEOUT` | `600` | Seconds to wait for one Workers AI run. |
| `CLOUDFLARE_LYRICS_MODEL` | `@cf/openai/whisper-large-v3-turbo` | Whisper model for lyrics on Workers AI. |
| `CLOUDFLARE_COMPOSER_MODEL` | `anthropic/claude-haiku-4.5` | Composer's writing model on Workers AI, until an admin picks one on `/admin/ai`. |
| `CLOUDFLARE_ARTWORK_MODEL` | `@cf/black-forest-labs/flux-1-schnell` | Image model that draws AI artwork on Workers AI. It must answer with a base64 `image`. |
| `CLOUDFLARE_MODERATOR_MODEL` | `anthropic/claude-haiku-4.5` | AI moderator model on Workers AI. It must read images. |

## Notifications

| Key | Default | What it does |
| --- | --- | --- |
| `VAPID_SUBJECT` | `mailto:hello@toneflix.ng` | Contact for push services. |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` | | Browser push keys. Create them once with `php artisan webpush:vapid`. Changing them later drops every existing push subscription. |
| `NOTIFY_MESSAGE_DELAY` | `5` | Minutes to wait before emailing or pushing unread messages. |
