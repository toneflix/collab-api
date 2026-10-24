# App .env

The app reads only variables that start with `VITE_`, and Vite bakes them in at build time. Change them, then rebuild.

The committed `.env` holds the defaults. Put your own values in `.env.local`, or in `.env.local.prod` for production builds only. Both are ignored by git and win over `.env`.

| Key | Default | What it does |
| --- | --- | --- |
| `VITE_APP_ENVIRONMENT` | `development` (`production` in a production build when empty) | Which environment the app runs in: `development`, `staging` or `production`. |
| `VITE_API_ENVIRONMENT` | `VITE_APP_ENVIRONMENT` | Which API to talk to. Lets a local app use the staging API. |
| `VITE_DEV_BASE_URL` | `http://127.0.0.1:8001` | API root for `development`. |
| `VITE_STAGING_BASE_URL` | | API root for `staging`. |
| `VITE_PROD_BASE_URL` | | API root for `production`. |
| `VITE_BASE_URL` | | Used when the matching base URL above is empty. |
| `VITE_FRONT_URL` | the page's origin | The app's public address, for share links. |
| `VITE_DEBUG` | `true` | Log extra detail in the browser console. Use `false` in production. |
| `VITE_LOG_LEVEL` | `error` | Lowest level that is logged. |
| `VITE_ASSET_URL` | | Optional prefix for static asset links. |
| `VITE_PREFER_ASSET` | | `true` rewrites absolute asset links to paths under `VITE_ASSET_URL`. |

Base URLs are the API's root, like `https://collab-api.example.com`. Do not add `/api/`; the app adds it.

## What comes from the API

These are not app variables. The app loads them from the API's `/api/initialize`, so they only need setting on the API:

- Paystack public key
- The realtime driver and its public settings (Reverb host, Pusher key and cluster, Ably, Firebase web config)
- The VAPID public key for browser push
