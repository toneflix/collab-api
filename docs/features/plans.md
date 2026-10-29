# Plans and features

Every account is on a plan. A plan is a price plus a set of **features**, and features are what the code checks before letting someone do something.

## The feature catalogue

Features are listed in `App\Enums\Feature`. Each has a type:

- **toggle**: on or off.
- **limit**: a number, where `0` means no limit.
- **credits**: an amount given each month, where `0` means none.

| Feature | Type | Fallback |
| --- | --- | --- |
| `downloads` Download tracks for offline listening | toggle | off |
| `badge` Premium badge on your profile | toggle | off |
| `sell_releases` Sell releases | toggle | on |
| `sell_stems` Sell stems | toggle | on |
| `receive_tips` Receive tips | toggle | on |
| `max_projects` Projects | limit | 10 |
| `max_songs` Compositions | limit | 10 |
| `max_collaborators` Collaborators per project | limit | 5 |
| `max_stem_size` Largest stem upload (MB) | limit | 50 |
| `max_storage` Sound storage (MB) | limit | 200 |
| `history_days` Version history (days) | limit | 30 |
| `ai_tools` AI studio tools | toggle | off |
| `ai_credits` AI credits each month | credits | 0 |
| `earn_streams` Earn from streams ([Stream earnings](./streams)) | toggle | off (on for Premium) |

The fallback is what an account gets when its plan does not set a feature.

### Downloads

A track can be downloaded when one of these holds (`App\Services\Releases\Downloads`):

- the person owns the release, or bought it (a paid release);
- their plan has `downloads`, the creator ticked **Allow downloads** when publishing (`releases.downloadable`), and the admin **Track Downloads** setting (`track_downloads`) is on.

Releases published before the opt-in existed keep downloads on. Tracks carry `downloadable` so the app can hide the download button.

## Plans

Plans live in the `plans` table. The first migration creates **Free** (the default plan) and **Premium**. Admins with `manage-plans` edit them at `/admin/plans`: price, features and billing options.

There is one plan per tier. Instead of separate monthly and yearly plans, each plan has `billing_options`, for example 6 cycles at 10% off and 12 at 20% off. People pick one at checkout on `/premium`, and the payment extends their subscription by that many cycles. Payment goes through Paystack; see [Payments](./payments).

## Checking a feature in code

```php
use App\Enums\Feature;
use App\Services\Limits;

$user->feature(Feature::SELL_STEMS);    // true/false for a toggle, a number for a limit

// Throws a validation error when the plan doesn't allow it
Limits::for($user)->ensureAllowed(Feature::SELL_STEMS, 'price');
Limits::for($user)->ensureBelow(Feature::MAX_PROJECTS, $count, 'name', 'Your plan allows :limit projects.');
```

## Adding a feature

1. Add a case to `App\Enums\Feature` and fill in `title()`, `description()`, `type()`, `unit()` and `fallback()`.
2. Check it with `$user->feature(Feature::X)` where the action happens.
3. If existing plans should get it, add a migration that sets it on them. The plans migration filled every feature with its fallback, so treat a fallback value as unset rather than using `??=`.

The admin plan editor and the `/premium` page read the catalogue from the API, so they need no change.
