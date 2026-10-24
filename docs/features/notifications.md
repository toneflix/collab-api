# Notifications

People hear about activity three ways: in the app (the bell and `/notifications`), by email, and by browser push.

## Categories

| Category | Examples |
| --- | --- |
| `collabs` | Requests to join a project, accepted or declined requests |
| `messages` | Unread conversation messages |
| `sales` | Release and stem sales, tips |
| `payouts` | A withdrawal was paid, failed or was declined |

In-app notifications are always on. Email and push can be turned off per category at `/account/notifications`. Collab requests can be accepted or declined right from `/notifications`.

## Browser push setup

1. Create VAPID keys once. This writes them to `.env`:

   ```bash
   php artisan webpush:vapid
   ```

2. Set `VAPID_SUBJECT` to a `mailto:` address or your site URL, and `FRONTEND_URL` to the app's address. Notification links and icons are built from `FRONTEND_URL`.
3. Install the `gmp` PHP extension (or at least `bcmath`). Push encryption is very slow without it, and the API logs a warning.

Keep the keys once they are live. New keys drop every existing push subscription.

The app registers `public/push-sw.js` as its service worker when someone turns push on. Push needs HTTPS (or `localhost`).

## Email

Email uses the standard Laravel mail settings in `.env` and goes through the queue.

## Adding a notification

1. Extend `App\Notifications\ActivityNotification` and set `category()`, `key()` (like `sales.tip`), `params()` and `path()`.
2. Add its subject, body and action text to `config/messages.php` under `activity::{category}`.
3. Send it with `$user->notify(...)`. The base class picks the channels: database always, plus mail and push when the person has them on.

Listeners are registered in `EventServiceProvider`. Event discovery is off, because it ran each listener twice.
