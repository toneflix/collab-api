# App: local setup

## Requirements

- Node.js 22
- Yarn 1. The repository only tracks `yarn.lock`, so use yarn, not npm.
- The API running locally (see [API: local setup](./api-local)).

## Install

```bash
git clone git@github.com:toneflix/collab.git
cd collab

yarn install
# the SSR server has its own dependencies
yarn --cwd src-ssr install
```

## Point it at your API

The committed `.env` already targets a local API at `http://127.0.0.1:8001`:

```dotenv
VITE_APP_ENVIRONMENT = "development"
VITE_API_ENVIRONMENT = "development"
VITE_DEV_API_BASE_URL = "http://127.0.0.1:8001"
```

Base URLs are the API's root. The app adds `/api/` itself. To keep personal changes out of git, put them in `.env.local`, which is read after `.env`. All variables are listed in [App .env](../reference/app-env).

## Run it

```bash
yarn dev              # SPA at http://localhost:9001
yarn quasar dev -m ssr  # SSR at http://localhost:9101
yarn worker:dev       # SSR on the Workers runtime at http://localhost:8787
```

`yarn worker:dev` builds the production SSR app and serves it with wrangler, so it does not reload on changes. See [Production deploy](./production#as-a-cloudflare-worker).

Sign in with `admin@toneflix.ng` / `password` after seeding the API. The admin pages are under `/admin`.

## Checks

```bash
yarn lint
yarn typecheck
yarn build
```

CI runs all three on every pull request (`.github/workflows/checks.yml`), and also builds the Cloudflare Worker.

## Things to know

- Paystack's public key, the realtime driver and its public keys, and the VAPID public key all come from the API (`/api/initialize`), so the app has no keys of its own.
- Microphone recording (voice notes, studio recording) needs a secure origin. `localhost` counts; a LAN IP over plain http does not.
- Browser push needs the service worker at `public/push-sw.js`, which is served from the site root.
