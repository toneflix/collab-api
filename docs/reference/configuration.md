# App configuration

Business settings that are not secrets live in the `configurations` table, not in `.env`. The seeder and migrations create them with defaults. They are cached; saving a row through the model clears the cache.

Admins with `manage-configuration` change them at `/admin/configuration`. `App\Services\Settings\AppConfiguration` lists the fields that page can edit and validates them.

To change one from the command line, save it through the model so the cached copy is cleared:

```bash
php artisan tinker --execute="App\Models\Configuration::where('key', 'app_currency')->first()->update(['value' => 'NGN']);"
```

If you edit the table directly, run `php artisan cache:forget configuration::build` afterwards.

## Settings that matter for going live

| Key | Default | What it does |
| --- | --- | --- |
| `app_name` | `APP_NAME` | Name shown in the app. |
| `app_email` | `support@toneflix.com.ng` | Support address. |
| `discord_url` | `https://discord.gg/u7fQdqQt8c` | Community invite on the Help page. Empty hides it. |
| `app_currency` | `NGN` | The currency of every price, balance and payout. It also decides which country Paystack pays out in; see [Payment providers](../guide/payments#app-currency). |
| `creator_share` | `85` | Percent of each sale and tip that goes to the creator. |
| `min_tip_amount` | `100` | Smallest tip, in the app currency. |
| `min_payout_amount` | `1000` | Smallest withdrawal, in the app currency. |
| `payout_hold_days` | `7` | Days before earnings can be withdrawn. |
| `payout_auto_approve` | `false` | Send withdrawals without an admin approving each one. |
| `verify_email` | `false` | Ask new users to confirm their email. |
| `verify_phone` | `false` | Ask new users to confirm their phone. |
| `token_lifespan` | `300` | Seconds a reset or verification code stays valid. |
| `reference_prefix` | `TFC-` | Prefix of payment references. |
| `allowed_audio_exts` | `.wav, .flac, .aiff, ...` | Audio file types people can upload. |

Settings for realtime, sounds, AI and payout providers are also stored here, but each has its own admin page: `/admin/realtime`, `/admin/sounds`, `/admin/ai`, `/admin/auth-pages` and Payouts > Providers.

`premium_*` and `free_*` keys are left over from before plans. The plans migration copied them into the `plans` table, and they are no longer read.
