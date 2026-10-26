# Overview

Toneflix Music is a music streaming and collaboration platform. It lives in two repositories:

| Repository | What it is | Stack |
| --- | --- | --- |
| [toneflix/collab-api](https://github.com/toneflix/collab-api) | The API, admin logic, queues and webhooks | Laravel 13, PHP 8.3, PostgreSQL |
| [toneflix/collab](https://github.com/toneflix/collab) | The web app people use, including the admin pages | Quasar 3 (app-vite), Vue 3.5, Pinia, alova, Tailwind 4 |

This site lives in `docs/` of the API repository.

## How the pieces fit

```text
 Browser (Quasar app) ──HTTPS /api/*──▶ Laravel API ──▶ PostgreSQL
        │                                   │
        │◀── websockets (Reverb, Pusher,    ├──▶ Queue worker (audio, AI, notifications, fingerprints)
        │     Ably or Firebase) ────────────┤
        │                                   ├──▶ Scheduler (recommendations, payouts, sound sync, AI sweep)
        │                                   │
        └── Paystack inline checkout        └──◀ Webhooks (Paystack, Flutterwave, Stripe)
```

- The app signs in with a Sanctum bearer token and talks to `<API root>/api/...`.
- Heavy work (audio encoding, Chromaprint fingerprints, AI jobs, emails and push) runs on the queue, so a **queue worker must always run**.
- Timed work (payout checks, AI refunds, Freesound sync, recommendations) runs from the **Laravel scheduler**, which needs a cron entry.
- All API keys and secrets come from the API's `.env`. Admin pages show which keys are set, but never take key input.

## Where to start

1. [Set up the API locally](./api-local)
2. [Set up the app locally](./app-local)
3. [Install FFmpeg with Chromaprint](./ffmpeg)
4. When you are ready to go live, follow [Production deploy](./production) and [Payment providers](./payments).
