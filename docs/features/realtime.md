# Realtime

Realtime keeps the studio, conversations and notifications live without reloading. It uses Laravel broadcasting on the API and [`@arkstack/realtime`](https://www.npmjs.com/package/@arkstack/realtime) in the app.

## Drivers

| Driver | What you need |
| --- | --- |
| `none` | Nothing. The app still works; people reload to see changes. |
| `reverb` | `REVERB_*` keys and a running `php artisan reverb:start`. Self-hosted and free. |
| `pusher` | A Pusher Channels app and its `PUSHER_*` keys. |
| `ably` | An Ably key in `ABLY_KEY`. Used through Ably's Pusher protocol. |
| `firebase` | A Firebase project with Realtime Database and the `FIREBASE_*` keys. `realtime:prune` deletes old events every hour. |

`REALTIME_DRIVER` sets the starting driver. Admins with `manage-configuration` can switch it at `/admin/realtime`, which also shows which keys each driver is missing. Keys always come from `.env`; see [API .env](../reference/api-env#realtime).

For Reverb in production, see [Reverb behind Nginx](../guide/production#reverb-behind-nginx).

## Channels

All channels are private. Their rules are in `App\Broadcasting\Channels`.

| Channel | Who can join | Used for |
| --- | --- | --- |
| `project-track.{id}` | The project's owner and collaborators | Stem changes and AI jobs in the studio |
| `conversation.{type}.{id}` | People who can see the thing being discussed | New messages |
| `user.{id}` | That user | Notifications, new-message alerts, their AI jobs (`ai.job`) and agent runs (`agent.run`) |
| `admin.content` | Admins with `manage-content` | Content agent runs (`agent.run`) and AI moderator reviews (`ai.review`) |

## How events flow

- The API sends events with `Realtime::send()`, which never throws, so a realtime outage never breaks a request.
- Each browser tab sends an `X-Realtime-Origin` id with its requests. Events carry that `origin`, and tabs skip events they caused themselves.
- In the app, `useRealtimeChannel` in `src/utils/realtime.ts` subscribes to a channel. SDKs are passed in through `importModule`, because Vite cannot resolve the package's dynamic imports.
- `useRealtimeChannel` returns `live`, which is true while the connection is up and the channel was joined. AI jobs, agent runs and AI moderator reviews poll for updates only while it is false (driver `none`, missing keys, or a dropped connection), through `useFallbackPoll`.

## Try it locally

```dotenv
REALTIME_DRIVER=reverb
REVERB_APP_ID=local
REVERB_APP_KEY=local-key
REVERB_APP_SECRET=local-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
```

```bash
php artisan reverb:start --port=8080
```

Open the same project track as two users in two browsers. Changes made by one appear for the other.
