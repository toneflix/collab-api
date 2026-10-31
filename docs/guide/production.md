# Production deploy

This page covers a single Linux server running the API, its worker, the scheduler and Reverb, plus the app built as a static SPA, an SSR server or a Cloudflare Worker. Split them across machines as you need; the steps stay the same.

Deploying to Railway or another Railpack host? See [Railpack and Railway](./railpack).

## API

### Server

- PHP 8.3 (FPM) with the extensions in [API: local setup](./api-local#requirements), including `gmp`
- PostgreSQL
- FFmpeg with Chromaprint ([FFmpeg and Chromaprint](./ffmpeg))
- Nginx or another web server pointing at `public/`
- Supervisor (or systemd) to keep the worker and Reverb running
- Redis is optional. The defaults use the database for cache, sessions and the queue.

### First deploy

```bash
git clone git@github.com:toneflix/collab-api.git /var/www/collab-api
cd /var/www/collab-api

cp .env.example .env            # then fill it in, see the API .env reference
composer update --no-dev --optimize-autoloader
php artisan key:generate
php artisan storage:link
php artisan webpush:vapid       # writes VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY to .env
php artisan migrate --seed --force
php artisan app:sync-roles
php artisan app:sync-roles 1 --roles=admin
```

::: warning Change the seeded admin
The seeder creates `admin@toneflix.ng` with the password `password`. Change its email and password right after the first deploy.
:::

::: tip composer.lock
`composer.lock` is not committed, so each deploy resolves packages again. Committing it would make deploys repeatable and let you use `composer install`.
:::

Set at least these in `.env` (all keys are in [API .env](../reference/api-env)):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://collab-api.example.com
FRONTEND_URL=https://collab.example.com
DB_CONNECTION=pgsql
QUEUE_CONNECTION=database
MAIL_MAILER=smtp
```

Set `MAIL_MAILER=sendpulse` with `SENDPULSE_API_ID` and `SENDPULSE_API_SECRET` where the host blocks outbound SMTP, as Railway does on its lower plans.

`APP_URL` must be the public `https://` address. Queued jobs and the default avatar build links from it, and a browser blocks `http://` audio on an `https://` page. The API trusts `X-Forwarded-*` headers from any proxy, since Railway and most hosts end TLS at their edge. If the server is reachable without a proxy, set `TRUSTED_PROXIES` to the proxy's addresses.

### Every deploy

```bash
php artisan down
git pull
composer update --no-dev --optimize-autoloader
php artisan migrate --force
php artisan app:sync-roles      # adds permissions new features need
php artisan words:import --if-empty  # loads the Composer dictionary once
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan up

php artisan queue:restart       # workers pick up the new code
php artisan reverb:restart      # only when Reverb runs here
```

`app:sync-roles` is safe to run every time. It creates roles and permissions that do not exist yet (for example `manage-content`) and leaves existing ones alone.

### Processes that must run

| Process | Command | Why |
| --- | --- | --- |
| Queue worker | `php artisan queue:work --tries=3 --timeout=3600` | Audio encoding, fingerprints, AI jobs, emails, push and unread-message notices. Nothing in the background happens without it. |
| Scheduler | cron, every minute | See below. |
| Reverb | `php artisan reverb:start` | Only when the realtime driver is `reverb`. |

A Supervisor example:

```ini
[program:collab-worker]
command=php /var/www/collab-api/artisan queue:work --tries=3 --timeout=3600
user=www-data
numprocs=2
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopwaitsecs=3600
stdout_logfile=/var/www/collab-api/storage/logs/worker.log

[program:collab-reverb]
command=php /var/www/collab-api/artisan reverb:start --host=127.0.0.1 --port=8080
user=www-data
autostart=true
autorestart=true
stdout_logfile=/var/www/collab-api/storage/logs/reverb.log
```

::: warning Queue timeouts
The AI job can run for 25 minutes and the sound library sync for an hour. `DB_QUEUE_RETRY_AFTER` (or `REDIS_QUEUE_RETRY_AFTER`) must stay longer than that, or a second worker starts the same job again while the first is still running. Both default to 3700 seconds, so only change them if you add a longer job.
:::

### Scheduler

Add one cron entry. `script.sh` in the repository does the same thing.

```text
* * * * * cd /var/www/collab-api && php artisan schedule:run >> /dev/null 2>&1
```

The scheduled tasks are listed in [Commands and schedule](../reference/commands).

### Reverb behind Nginx

Give Reverb its own host name and let Nginx terminate TLS:

```nginx
server {
    listen 443 ssl;
    server_name ws.collab.example.com;

    location / {
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header Scheme $scheme;
        proxy_set_header SERVER_PORT $server_port;
        proxy_set_header REMOTE_ADDR $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_pass http://127.0.0.1:8080;
    }
}
```

```dotenv
REALTIME_DRIVER=reverb
REVERB_HOST=ws.collab.example.com   # used by the API and sent to browsers
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=127.0.0.1         # where reverb:start listens
REVERB_SERVER_PORT=8080
```

### File storage

Uploads (avatars, artwork, tracks, stems, previews, voice notes and sounds) stay on the server by default. To keep them in Amazon S3 or an S3-compatible store (R2, MinIO, Spaces), set `FILESYSTEM_DISK=s3` and the `AWS_*` keys in [API .env](../reference/api-env#storage).

Keep the bucket private. File links point straight at the bucket, are signed, and last `FILEABLE_TEMPORARY_URL_TTL` minutes (12 hours by default). The API doesn't ask the bucket whether a file exists before linking it, so listing files costs no bucket requests.

The studio loads stems with `fetch`, so the bucket needs a CORS rule. Some links (library sounds) still go through the API, which sends the browser on to a signed bucket link and makes its `Origin` header `null`, so allow every origin. The signed links are what keep files private.

```json
[
  {
    "AllowedOrigins": ["*"],
    "AllowedMethods": ["GET", "HEAD"],
    "AllowedHeaders": ["*"],
    "ExposeHeaders": ["Content-Length", "Content-Range", "Accept-Ranges"]
  }
]
```

FFmpeg and Chromaprint work on a local copy of each file, so `FFMPEG_TEMPORARY_FILES_ROOT` (or the system temp folder) needs room for the largest upload.

To move existing uploads, copy `storage/app/public/` to the root of the bucket, so `storage/app/public/media/` becomes `media/`:

```bash
aws s3 sync storage/app/public/ s3://<bucket>/ --exclude ".gitignore"
```

### Webhooks

Register these with each provider you turn on. Details are in [Payment providers](./payments).

| Provider | URL |
| --- | --- |
| Paystack | `https://<api>/api/payments/paystack/webhook` |
| Flutterwave | `https://<api>/api/payouts/flutterwave/webhook` |
| Stripe | `https://<api>/api/payouts/stripe/webhook` |

## App

All `VITE_*` values are baked in at build time, so set them before building. See [App .env](../reference/app-env).

```bash
git clone git@github.com:toneflix/collab.git
cd collab
yarn install --frozen-lockfile
```

```dotenv
# .env.local.prod on the build machine
VITE_APP_ENVIRONMENT = "production"
VITE_API_ENVIRONMENT = "production"
VITE_PROD_API_BASE_URL = "https://collab-api.example.com"
VITE_FRONT_URL = "https://collab.example.com"
VITE_DEBUG = false
```

### As a static SPA

```bash
yarn build          # output in dist/spa
```

Serve `dist/spa` from any static host. The router uses history mode, so send unknown paths to `index.html`:

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```

### As an SSR server

```bash
yarn --cwd src-ssr install --frozen-lockfile
yarn quasar build -m ssr   # output in dist/ssr
cd dist/ssr && yarn install --production && node index.js
```

It listens on port 3000 (`PORT` changes it). Keep it running with Supervisor or pm2, and put Nginx in front.

### As a Cloudflare Worker

The same SSR app runs on [Cloudflare Workers](https://developers.cloudflare.com/workers/), so there is no server to keep running. The Worker runs the Express server through Workers' Node.js support, and Cloudflare serves the files in `dist/ssr/client` before the Worker is called.

```bash
yarn --cwd src-ssr install --frozen-lockfile
yarn worker:dev        # build, then serve it with wrangler at http://localhost:8787
yarn wrangler login    # once, on your machine
yarn worker:deploy     # build and deploy
```

`yarn build:worker` builds `dist/ssr` for Workers: it bundles the server's packages with their Node builds and leaves out `public/_redirects`, since the Worker renders every page. The Worker's name, compatibility date and flags live in `wrangler.jsonc`. Add a custom domain to the Worker in Cloudflare under **Workers & Pages > toneflix-collab > Settings > Domains & Routes**.

The page HTML is rendered on each request and needs the API, so `VITE_PROD_API_BASE_URL` must be reachable from Cloudflare.

#### Deploy from GitHub

The `Deploy to Cloudflare Workers` workflow (`.github/workflows/deploy-worker.yml` in the app repository) deploys on every push to `main`, and can be run by hand from the Actions tab. It needs:

| Name | Kind | What it is |
| --- | --- | --- |
| `CLOUDFLARE_API_TOKEN` | secret | An API token with the **Account > Workers Scripts > Edit** permission (the **Edit Cloudflare Workers** template works). |
| `CLOUDFLARE_ACCOUNT_ID` | secret | Your Cloudflare account ID. |
| `CLOUDFLARE_WORKER_NAME` | variable, optional | The Worker's name. Defaults to `toneflix-collab`. |
| `VITE_*` | variables, optional | Any app variable from [App .env](../reference/app-env). They override the committed `.env`. Set at least `VITE_PROD_API_BASE_URL` and `VITE_FRONT_URL`. |

The workflow builds with `VITE_APP_ENVIRONMENT` and `VITE_API_ENVIRONMENT` set to `production`. The `Checks` workflow also builds the Worker on every pull request, so a change that breaks it fails there first.

## Go-live checklist

- [ ] `APP_DEBUG=false`, real mail settings, seeded admin password changed
- [ ] Queue worker, cron and (if used) Reverb running
- [ ] FFmpeg shows `chromaprint` in `ffmpeg -muxers`
- [ ] Bucket CORS rule added, if `FILESYSTEM_DISK=s3`
- [ ] `php artisan webpush:vapid` run once, `FRONTEND_URL` set
- [ ] `app_currency`, `creator_share` and payout limits set at `/admin/configuration`
- [ ] Paystack webhook added and transfer OTP turned off
- [ ] Stripe and Flutterwave webhooks added, if those payouts are on
- [ ] Freesound callback URL set, if Freesound is on
- [ ] `REPLICATE_API_TOKEN` set, if AI tools are on
