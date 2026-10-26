# Sign-in and verification

## Pages

The app's auth pages are `/login`, `/register`, `/forgot/password`, `/reset/password` and `/account/verify/{email|phone}`. On tablets and desktops they show the form on the left and a banner on the right. Phones show the form only.

Admins with `manage-configuration` change them at `/admin/auth-pages`:

- **Layout**: the form and banner widths (45/55 by default, 40/60, 50/50, 60/40, or form only).
- **Banner image**: an uploaded image behind the banner text. Without one, the banner shows an animated waveform.
- **Text**: every heading, label, placeholder, button and link on each page. An empty field uses the default.

`App\Services\Settings\AuthPages` holds the presets and default texts. Overrides are saved in the `auth_pages` configuration row, and the app reads the merged result from `auth` in `GET /api/initialize`.

The Terms of Service and Privacy Policy live in the app at `/terms` and `/privacy`.

## Sign-up and interests

Sign-up has three steps:

1. **Account**: `account_type`, either `creator` or `listener`.
2. **About you**: name and `identities`, the roles that fit the account type (artist, producer, DJ and so on for creators; fan, curator, dancer and so on for listeners). `App\Enums\AccountType` holds both lists, and `GET /api/initialize` returns them as `accountTypes`.
3. **Sign-in**: email, optional phone, password and the terms.

All three go to `POST /api/auth/register` together.

After sign-up, or a sign-in while the account has none, the app opens `/account/interests` to pick genres (after any pending verification). It can be skipped, and changed later in account settings.

| Endpoint | What it does |
| --- | --- |
| `GET /api/account/interests` | Account type, identities, picked genres and `interestsAt`. |
| `PUT /api/account/interests` | Saves any of `account_type`, `identities` and `genres` (1 to 20 genre ids). Saving genres sets `interestsAt`. |

Interests curate what people hear: `GET /api/tracks?filters=for_you` (and `for_you` on `GET /api/explore`, the home page's "Made for you" shelf) returns tracks in those genres, and the player's up-next queue plays them before unrelated tracks.

## Password reset

1. `POST /api/auth/forgot-password` with `email` sends a six-digit code and a link (`/reset/password?token=…`).
2. `POST /api/auth/reset-password/check-code` with `code` accepts either the code or the link token.
3. `POST /api/auth/reset-password` with `code`, `password` and `password_confirmation` sets the password. It also marks the email verified, since the code reached that inbox.

## Email and phone verification

When `verify_email` or `verify_phone` is on, sign-up sends a code to that email address or phone number (`App\Listeners\SendVerificationCodes`). Until the user confirms it, the app sends them to `/account/verify/{type}` whenever they open a page that needs an account. Public pages stay open.

| Endpoint | What it does |
| --- | --- |
| `GET /api/verify/with-code/{type}` | Whether the channel is verified, and when a new code can be sent. |
| `POST /api/verify/with-code/{type}` | Sends a new code. |
| `PUT /api/verify/with-code/{type}` | Checks `code` and marks the channel verified. |

The email contains a link to `/account/verify/email?code=…`, which fills in the code. Phone codes go by SMS through the configured SMS provider.

Codes expire after `token_lifespan` seconds (300 by default). A new code can be requested 60 seconds after the last one.
