# AI tools and credits

The studio's AI panel can **generate music** from a prompt and **split a track into stems**, and the lyric sheet can **write out the lyrics** sung on a stem. All run on [Replicate](https://replicate.com) with one API token, and are paid for with AI credits. Released tracks also get their lyrics written out automatically, paid for by the app (see [Lyrics](#lyrics)).

## Setup

1. Set `REPLICATE_API_TOKEN` in the API's `.env`. Replicate allows only 6 new predictions a minute with a burst of 1 while the account has under $5 of credit or no payment method, which throttles the split that follows a song. Add a payment method and turn on auto-reload. Throttled requests wait and are tried again up to 3 times.
2. Keep a queue worker running. Each AI request is a queued `RunAiJob` that can take up to 25 minutes.
3. Keep the scheduler running. `ai:sweep` refunds jobs that stopped without finishing.
4. On `/admin/ai` (needs `manage-configuration`), check that AI Tools is on and set prices.
5. Give plans the `ai_tools` feature and a monthly `ai_credits` amount at `/admin/plans`. Premium starts with AI tools and 100 credits a month.

The tools stay hidden unless admins turned them on, the token is set, and the person's plan includes `ai_tools`.

## Models

| Task | Default model | Change with |
| --- | --- | --- |
| Music generation | `elevenlabs/music` | `REPLICATE_MUSIC_MODEL` |
| Stem separation | `ryan5453/demucs` | `REPLICATE_STEMS_MODEL` |
| Lyrics transcription | `openai/whisper` (large-v3) | `REPLICATE_LYRICS_MODEL` |

All are cleared for commercial use: ElevenLabs Music under its music terms, Demucs and Whisper under MIT. Generated music is added to the project track as a stem, or split into stems first if the person asks.

## Credits

- Each plan gives a number of credits per calendar month. They are used first and reset each month.
- Bought credits come on top and never expire.
- A failed job refunds its credits.
- If a song is made but splitting it into stems fails, the song is added whole and only the split's credits are refunded.

## When a job fails

Failures are logged at `error` level with the exception, so they show with `LOG_LEVEL=error`. The reason is also kept on the job (`ai_jobs.failure`). List recent problems with:

```bash
php artisan ai:jobs --failures
php artisan ai:jobs --status=failed --user=12
```

Admin settings on `/admin/ai`:

| Setting | Default |
| --- | --- |
| Price per credit | By app currency, for example 0.02 USD or 30 NGN |
| Credit packs | `100, 500:10, 2000:20` (pack size, then an optional discount in percent) |
| Music credits per 10 seconds | 5 |
| Stem split credits | 5 |
| Longest generated track | 300 seconds |
| Lyrics credits | 2 |
| Transcribe released tracks | On |
| More explicit words | Empty, added to the built-in list in `config/lyrics.php` |
| More words to review | Empty, added to the built-in list in `config/lyrics.php` |

People buy packs at `/studio/credits` through Paystack, and see their usage there too.

## Lyrics

Lyrics are kept per released track and per project track in the `lyrics` table, as lines with a start and end time in seconds (or none, for lines written before they are synced).

- **Released tracks.** Saving a track with new audio queues `TranscribeTrack`. A track released from the studio takes its project track's lyric sheet. Otherwise, when AI tools and "Transcribe released tracks" are on, Whisper writes the lyrics out from the track's audio (or its project's preview mix). This costs the app about $0.001 a track, and no credits. Lyrics the creator wrote themselves are never replaced. Creators correct lyrics with `PUT /api/creator/tracks/{track}/lyrics`.
- **The studio.** Collaborators edit the lyric sheet of a project track, and can have a stem transcribed for the lyrics credits. Lines are timed to where the stem sits on the track. Saving the sheet updates every track released from it.
- **The player** reads `GET /api/tracks/{track}/lyrics` and highlights the line being sung.
- **Search.** `GET /api/tracks?search=` matches titles and lyrics (PostgreSQL full text). Each result has `lyricMatch`, the line that matched.
- **Explicit content.** Words from the explicit list mark a released track explicit. Words from the review list send it to the moderation queue as a "Lyrics check" flag, once for each set of words a moderator hasn't already reviewed. Both lists ignore case and match whole words only.

Whisper hears vocals best on their own, so transcribing a vocals stem (for example one made with the stem split) gives cleaner lyrics than a full mix.

## In code

- `App\Services\Ai\AiStudio` starts, runs and fails jobs (`ai_jobs` table).
- `App\Services\Ai\Credits` spends, refunds and adds credits.
- Each task is an `AiTask` in `App\Services\Ai\Tasks` (`GenerateMusic`, `SplitStems`, `TranscribeLyrics`).
- `App\Services\Lyrics\Lyrics` saves lyrics and applies the explicit and review checks (`LyricsCheck`). `Transcriber` calls Whisper.
- `laravel/ai` has no Replicate driver, so a custom `replicate` driver is registered in `AppServiceProvider`, and Demucs is called through `ReplicateClient`.
