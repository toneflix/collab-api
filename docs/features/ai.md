# AI tools and credits

The studio's AI panel can **generate music** from a prompt and **split a track into stems**, and the lyric sheet can **write out the lyrics** sung on a stem. They run on two engines, [Replicate](https://replicate.com) (the default for everything) and [Suno](#suno) (optional, for music and stems), and are paid for with AI credits. Released tracks also get their lyrics written out automatically, paid for by the app (see [Lyrics](#lyrics)).

## Setup

1. Set `REPLICATE_API_TOKEN` in the API's `.env`, and `SUNO_API_KEY` to use Suno. Replicate allows only 6 new predictions a minute with a burst of 1 while the account has under $5 of credit or no payment method, which throttles the split that follows a song. Add a payment method and turn on auto-reload. Throttled requests wait and are tried again up to 3 times.
2. Keep a queue worker running. Each AI request is a queued `RunAiJob` that can take up to 25 minutes.
3. Keep the scheduler running. `ai:sweep` refunds jobs that stopped without finishing.
4. On `/admin/ai` (needs `manage-configuration`), check that AI Tools is on and set prices.
5. Give plans the `ai_tools` feature and a monthly `ai_credits` amount at `/admin/plans`. Premium starts with AI tools and 100 credits a month.

The tools stay hidden unless admins turned them on, an engine's key is set, and the person's plan includes `ai_tools`. A tool whose engine has no key turns requests away before any credits are taken.

## Models

| Task | Default model | Change with |
| --- | --- | --- |
| Music generation | `elevenlabs/music` | "Music model" on `/admin/ai` (`REPLICATE_MUSIC_MODEL` sets the default) |
| Stem separation | `ryan5453/demucs` | "Stem split engine" on `/admin/ai` (Demucs or Suno); `REPLICATE_STEMS_MODEL` |
| Lyrics transcription | `openai/whisper` (large-v3) | `REPLICATE_LYRICS_MODEL` |

Generated music is added to the project track as a stem, or split into stems first if the person asks.

### Music models

Admins pick one music model on `/admin/ai`, and each has its own credit price. A job keeps the model and price it started with.

| Model | Credits (default) | Length | Lyrics | Reference audio |
| --- | --- | --- | --- | --- |
| `elevenlabs/music` | 5 per 10 seconds | Chosen, up to 5 min | In the prompt | No |
| `minimax/music-2.5` | 30 per song | Model decides, up to about 5 min | Required, up to 3500 characters | No |
| `minimax/music-1.5` | 10 per song | Model decides, up to 4 min | Required, 10 to 600 characters | Song, voice or instrumental, over 15 s |
| `stability-ai/stable-audio-2.5` | 40 per track | Chosen, up to 190 s | No vocals | No |
| `suno/music` (Suno engine) | 12 per song | Chosen on v5.5 and v6, 10 s to 5 min | Optional, up to 5000 characters; Suno writes them when left out | Song, over 6 s |

- MiniMax 2.5 is asked for lossless WAV. WAV output is split as it is and stored once as a 320 kbps MP3.
- A reference is a stem on the track (`reference_stem_id`) or an uploaded MP3 or WAV up to 20 MB (`reference`), with `reference_kind`. Uploads wait on the uploads disk under `ai/references/` and are deleted once the job has run.
- Models are classes in `App\Services\Ai\Music`. `MusicModel` holds what every model shares; `ReplicateMusic` runs a Replicate model through the Laravel AI SDK, and `SunoMusic` runs Suno. A subclass sets its id, engine, credit setting, pricing, the form rules, how the form maps to the model's inputs and how a song is made (`generate()`). Add one to `MusicModel::MODELS` and its credit setting to `AiSettings::FIELDS`.
- The default prices are rough, since Replicate doesn't publish all of these. Check the cost of a run on Replicate and adjust.
- Licensing: ElevenLabs Music is cleared for commercial use under its music terms, and Stable Audio is trained on licensed music. MiniMax's terms only say the customer owns outputs "to the extent permitted by law", with no explicit commercial grant. Demucs and Whisper are MIT.

## Suno

Suno is an optional second engine, through [sunoapi.org](https://sunoapi.org). Replicate stays the default for every tool; admins switch music to Suno with "Music model" and stems with "Stem split engine" on `/admin/ai`.

- **Not Suno itself.** sunoapi.org is a reseller run by MIRA MUSE LLC; Suno has no public API. Its terms say nothing about who owns generated songs, so treat Suno output like MiniMax's for anything sold.
- **Model version.** "Suno model version" picks v6, v5.5 (default), v5 or v4.5. Only v6 and v5.5 follow the requested length. A job keeps the version it started with (`input.suno_version`).
- **Lyrics.** With lyrics, or for an instrumental, the prompt is the style. Without lyrics Suno writes them from the prompt, and a reference song can guide it (the requested length is then up to Suno).
- **Two versions.** Every request makes two versions at one price. With "Keep both Suno versions" off, only the first is kept. With it on, both are stored under `ai/versions/{job}` and the job lists them (`versions`, each with a signed `url` that plays it for 6 hours). The owner picks one with `POST /api/creator/ai/jobs/{job}/versions/{index}`; it goes on the track (split into stems if asked) through the queued `AddMusicVersion` job, and the other is deleted. `ai:sweep` deletes versions nobody picked after 7 days.
- **Stems.** Suno splits into vocals and instrumental, or up to 12 stems ("Suno stems"). A song Suno made is split by its Suno id; other audio is uploaded to sunoapi.org's file host first (kept 3 days). Parts come back as MP3 and are stored as they are.
- **Polling.** Suno requires a callback URL for every task. It points at `POST /api/ai/suno/callback`, which only answers, because callbacks carry no signature. Jobs poll Suno every 5 seconds instead, up to `SUNO_TIMEOUT`.
- **Prices.** A Suno credit costs $0.005. A song request is 12 Suno credits (about $0.06), vocals/instrumental 10 ($0.05), 12 stems 50 ($0.25). Default AI credit prices keep the usual margin: 12 per song, 50 per Suno split. sunoapi.org allows 20 requests per 10 seconds.

## Audio quality

- **Stems add up to the song.** Demucs (`htdemucs` weights, set with `REPLICATE_STEMS_VARIANT`) returns WAV. Vocals, drums and bass are kept as separated, and "other" is rebuilt with FFmpeg as the song minus those three, so the four stems played together sound exactly like the song. If the rebuilt part is more than 6 dB louder than Demucs' own "other", the parts didn't line up and Demucs' "other" is kept. Each stem is then encoded once as a 320 kbps MP3 (`App\Services\Audio\StemAudio`).
- **Studio mixes** are rendered on the server with FFmpeg from the saved arrangement and encoded once as a 320 kbps MP3 (see [Studio](./studio#preview-mix)).
- **Uploaded MP3s are kept as they are.** `ProcessAudio` only re-encodes lossless uploads (WAV, FLAC, AIFF, ALAC), since encoding an MP3 again only loses quality.

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
| Music model | `elevenlabs/music` |
| ElevenLabs Music credits per 10 seconds | 5 |
| MiniMax Music 2.5 credits per song | 30 |
| MiniMax Music 1.5 credits per song | 10 |
| Stable Audio 2.5 credits per track | 40 |
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
- **The studio.** Collaborators edit the lyric sheet of a project track, and can have a stem transcribed for the lyrics credits. Lines are timed to where the stem sits on the track. Saving the sheet updates every track released from it. With `mode: "sync"` (`POST .../stems/{stem}/lyrics`) the sheet keeps its own words and only takes the times: `LyricAligner` matches the written words to the words Whisper heard, in order, and lines nobody sang keep the times they had.
- **Composer songs.** A song made into a project writes its lines to the project track's lyric sheet whenever it is saved, keeping the times of lines that are still there, so released tracks follow the song without losing their sync.
- **The player** reads `GET /api/tracks/{track}/lyrics` and highlights the line being sung.
- **Search.** `GET /api/tracks?search=` matches titles and lyrics (PostgreSQL full text). Each result has `lyricMatch`, the line that matched.
- **Explicit content.** Words from the explicit list mark a released track explicit. Words from the review list send it to the moderation queue as a "Lyrics check" flag, once for each set of words a moderator hasn't already reviewed. Both lists ignore case and match whole words only.

Whisper hears vocals best on their own, so transcribing a vocals stem (for example one made with the stem split) gives cleaner lyrics than a full mix.

## Tempo and key

New tracks and studio mixes are tagged with their BPM and musical key. This runs on the API's own server with FFmpeg, uses no Replicate calls and no credits, and works with AI tools turned off. Admins turn it off with "Detect tempo and key" at `/admin/ai`.

- **When it runs.** Saving a track with new audio, or a project track with a new preview mix, queues `TagAudio`. A track released from the studio takes its project track's tags instead. A project track's tags also pass to its draft releases.
- **How it works.** FFmpeg decodes up to 180 seconds to mono at 11 kHz. Tempo comes from a spectral flux onset curve and its autocorrelation, weighted toward 120 BPM. Key comes from the spectral peaks folded into 12 pitch classes, tuned to the recording, and matched against major and minor key profiles (Albrecht and Shanahan, 2013). A song takes about 2 to 5 seconds. Guesses below 0.2 confidence are left empty.
- **Licensing.** All of it is our own code on published methods, with FFmpeg already in the image. Essentia (AGPL, and its models are CC BY-NC-SA), aubio and libKeyFinder (GPL) were left out.
- **Accuracy.** Like every detector, it sometimes picks half, double or 4/3 of the tempo, or the relative or parallel key. Creators correct tags with `PUT /api/creator/tracks/{track}/tags` or `PUT /api/creator/projects/{project}/tracks/{track}/tags` (`bpm`, `key` like "A minor"). Corrected tags are never replaced. Sending neither detects them again.
- **API.** Tracks and project tracks carry `tags`: `bpm`, `key`, `keyShort` ("Am"), `camelot` ("8A") and `source` (`detected` or `manual`). `GET /api/tracks` filters with `bpm_min`, `bpm_max` and `key` (a full name, short name or Camelot code, comma separated), and `compatible=1` adds the keys that mix with it on the Camelot wheel.
- **Older tracks.** Run `php artisan tags:detect` once to queue every track and mix without tags. `--all` detects again on everything not set by hand.

## In code

- `App\Services\Ai\AiStudio` starts, runs and fails jobs (`ai_jobs` table).
- `App\Services\Ai\Credits` spends, refunds and adds credits.
- Each task is an `AiTask` in `App\Services\Ai\Tasks` (`GenerateMusic`, `SplitStems`, `TranscribeLyrics`) and names the `AiEngine` it runs on.
- Stem splitting goes through a `StemSplitter` in `App\Services\Ai\Stems`: `DemucsSplitter` (Replicate) or `SunoSplitter`.
- `App\Services\Ai\Suno\SunoClient` starts Suno tasks, polls them, uploads and downloads files.
- `App\Services\Lyrics\Lyrics` saves lyrics and applies the explicit and review checks (`LyricsCheck`). `Transcriber` calls Whisper.
- `App\Services\Audio\AudioTagger` saves tempo and key tags. `Analysis\AudioAnalyzer` decodes audio for `TempoDetector` and `KeyDetector`.
- `laravel/ai` has no Replicate driver, so a custom `replicate` driver is registered in `AppServiceProvider`, and Demucs is called through `ReplicateClient`.
