# AI tools and credits

The studio's AI panel can **generate music** from a prompt and **split a track into stems**. Both run on [Replicate](https://replicate.com) with one API token, and both are paid for with AI credits.

## Setup

1. Set `REPLICATE_API_TOKEN` in the API's `.env`.
2. Keep a queue worker running. Each AI request is a queued `RunAiJob` that can take up to 25 minutes, so check `DB_QUEUE_RETRY_AFTER` (see [Production deploy](../guide/production#processes-that-must-run)).
3. Keep the scheduler running. `ai:sweep` refunds jobs that stopped without finishing.
4. On `/admin/ai` (needs `manage-configuration`), check that AI Tools is on and set prices.
5. Give plans the `ai_tools` feature and a monthly `ai_credits` amount at `/admin/plans`. Premium starts with AI tools and 100 credits a month.

The tools stay hidden unless admins turned them on, the token is set, and the person's plan includes `ai_tools`.

## Models

| Task | Default model | Change with |
| --- | --- | --- |
| Music generation | `elevenlabs/music` | `REPLICATE_MUSIC_MODEL` |
| Stem separation | `ryan5453/demucs` | `REPLICATE_STEMS_MODEL` |

Both are cleared for commercial use: ElevenLabs Music under its music terms, Demucs under MIT. Generated music is added to the project track as a stem, or split into stems first if the person asks.

## Credits

- Each plan gives a number of credits per calendar month. They are used first and reset each month.
- Bought credits come on top and never expire.
- A failed job refunds its credits.

Admin settings on `/admin/ai`:

| Setting | Default |
| --- | --- |
| Price per credit | By app currency, for example 0.02 USD or 30 NGN |
| Credit packs | `100, 500:10, 2000:20` (pack size, then an optional discount in percent) |
| Music credits per 10 seconds | 5 |
| Stem split credits | 5 |
| Longest generated track | 300 seconds |

People buy packs at `/studio/credits` through Paystack, and see their usage there too.

## In code

- `App\Services\Ai\AiStudio` starts, runs and fails jobs (`ai_jobs` table).
- `App\Services\Ai\Credits` spends, refunds and adds credits.
- Each task is an `AiTask` in `App\Services\Ai\Tasks` (`GenerateMusic`, `SplitStems`).
- `laravel/ai` has no Replicate driver, so a custom `replicate` driver is registered in `AppServiceProvider`, and Demucs is called through `ReplicateClient`.
