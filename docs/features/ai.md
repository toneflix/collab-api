# AI tools and credits

The studio's AI panel can **generate music** from a prompt and **split a track into stems**, the DAW can **rewrite a section of a stem** from a prompt, the lyric sheet can **write out the lyrics** sung on a stem, and every artwork upload can **draw artwork** from a prompt. They run on three engines, [Replicate](https://replicate.com) (the default for everything), [Suno](#suno) (optional, for music and stems) and [Cloudflare Workers AI](#cloudflare-workers-ai) (optional, for music, lyrics, artwork, Composer and the AI moderator), and are paid for with AI credits. Released tracks also get their lyrics written out automatically, paid for by the app (see [Lyrics](#lyrics)).

## Setup

1. Set `REPLICATE_API_TOKEN` in the API's `.env`, `SUNO_API_KEY` to use Suno, and `CLOUDFLARE_ACCOUNT_ID` with `CLOUDFLARE_API_TOKEN` to use Workers AI. Replicate allows only 6 new predictions a minute with a burst of 1 while the account has under $5 of credit or no payment method, which throttles the split that follows a song. Add a payment method and turn on auto-reload. Throttled requests wait and are tried again up to 3 times.
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
| Lyrics transcription | `openai/whisper` (large-v3) | "Lyrics engine" on `/admin/ai` (Replicate or Workers AI); `REPLICATE_LYRICS_MODEL`, `CLOUDFLARE_LYRICS_MODEL` |
| Artwork | `black-forest-labs/flux-schnell` | "Artwork engine" on `/admin/ai` (Replicate or Workers AI); `REPLICATE_ARTWORK_MODEL`, `CLOUDFLARE_ARTWORK_MODEL` |

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
| `elevenlabs/music-v2` (Workers AI) | 5 per 10 seconds | Chosen, up to the longest generated track | In the prompt | No |
| `minimax/music-2.6` (Workers AI) | 30 per song | Model decides | Optional, up to 3500 characters; MiniMax writes them when left out | No |

- MiniMax 2.5 is asked for lossless WAV. WAV output is split as it is and stored once as a 320 kbps MP3.
- A reference is a stem on the track (`reference_stem_id`) or an uploaded MP3 or WAV up to 20 MB (`reference`), with `reference_kind`. Uploads wait on the uploads disk under `ai/references/` and are deleted once the job has run.
- Models are classes in `App\Services\Ai\Music`. `MusicModel` holds what every model shares; `ReplicateMusic` runs a Replicate model through the Laravel AI SDK, `SunoMusic` runs Suno, and `CloudflareMusic` runs a Workers AI partner model. A subclass sets its id, engine, credit setting, pricing, the form rules, how the form maps to the model's inputs and how a song is made (`generate()`). Add one to `MusicModel::MODELS` and its credit setting to `AiSettings::FIELDS`.
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

## Section edits

In the DAW, people select a part of a stem, choose **Edit with AI** and describe the change, with optional lyrics for that part. `POST /api/creator/projects/{project}/tracks/{track}/stems/{stem}/edit` takes `prompt`, `lyrics`, and `start`/`end` in seconds of the stem's audio file, and starts an `edit` job for "Section edit credits" (5 by default).

- **Engine.** Only Suno can rewrite part of existing audio: its replace section call takes uploaded audio and a start and end time. The Replicate and Workers AI music models only make new music. Editors implement `App\Services\Ai\Edit\SectionEditor` and are listed in `EditSection::EDITORS`.
- **Limits.** Suno rewrites at least 10 seconds and at most half of what it's given. `EditWindow` widens the selection (plus 60 ms for crossfades) to 10 seconds and sends up to 10 seconds of audio on each side, so a stem needs at least 20 seconds of audio and a selection can be at most about half of it. The API answers 422 before charging when a selection doesn't fit.
- **Splicing.** FFmpeg cuts the clip, and after Suno answers it takes the rewritten part back out, stretched in time (pitch kept) to its original length when Suno's clip came back longer or shorter, and splices only the selected part into the stem with 60 ms crossfades.
- **Result.** The edit goes on the track as a new stem, "Title (AI edit)", with the original's place, clips, volume and effects, and the original is muted. Version history can undo it.

## Artwork

Project, release, track and artist forms have a **Generate with AI** button beside the image upload. People describe the picture, pick one of the images it draws, and the form saves it like an uploaded image.

- **API.** `POST /api/creator/ai/artwork` takes `kind` (`project`, `release`, `track`, `song`, `artist` or `artist_cover`), `prompt`, and optionally the `title`, `artist` and `genre` of what it's for. It starts an `artwork` job (no project track) for "Artwork credits" (3 by default). `GET /api/creator/ai/artwork?kind=` lists the person's latest artwork jobs.
- **Images.** A job draws "Artwork images per request" images (4 by default, at most 4). `App\Enums\ArtworkKind` sets the shape (square, or 2:1 for artist covers) and turns the prompt into one for the model with the genre. Titles and names stay out of it, because the model writes quoted words onto the image. The images are cropped to shape, stored as JPEGs under `ai/artwork/{job}` and listed on the job as `images`, each with a signed `url` that works for a day. `ai:sweep` deletes them after 7 days.
- **Engines.** FLUX.1 [schnell] on Replicate draws all images in one run, about $0.003 each. On Workers AI it draws one square image per run. Models implement `App\Services\Ai\Artwork\ArtworkModel` and are listed in `GenerateArtwork::MODELS`. FLUX.1 [schnell] is Apache-2.0.
- **Other forms.** The app's `AiArtworkButton` component works for any form with an image: it takes the kind and the form's title, artist and genre, and emits the picked image as a `File`.

## Cloudflare Workers AI

[Workers AI](https://developers.cloudflare.com/ai/models/) is an optional third engine. Replicate stays the default; admins move each tool over on `/admin/ai`.

- **Keys.** `CLOUDFLARE_ACCOUNT_ID` and an API token with the Workers AI permission in `CLOUDFLARE_API_TOKEN`. Partner models (ElevenLabs, MiniMax, Anthropic) are billed through Cloudflare's unified billing, so the account needs credit for them.
- **Music.** "Music model" offers ElevenLabs Music v2 (`elevenlabs/music-v2`, $0.0025 per second, so the default 5 credits per 10 seconds keeps the usual margin) and MiniMax Music 2.6 (`minimax/music-2.6`, $0.15 a song plus $0.01 when it writes the lyrics; 30 credits by default). Both answer with a link to the finished song in the same request.
- **Lyrics.** "Lyrics engine" runs Whisper (`@cf/openai/whisper-large-v3-turbo`, `CLOUDFLARE_LYRICS_MODEL`). Workers AI takes the audio inside the request, so it is first made into a 16 kHz mono MP3.
- **Composer.** "Composer engine" with "Composer writing model on Cloudflare" (`anthropic/claude-haiku-4.5` by default, `CLOUDFLARE_COMPOSER_MODEL`). Any Workers AI text model works: Anthropic models go through the Messages API, the others through the OpenAI-style chat API.
- **Artwork.** "Artwork engine" runs FLUX.1 [schnell] (`@cf/black-forest-labs/flux-1-schnell`, `CLOUDFLARE_ARTWORK_MODEL`), one square image per run, cropped for wide covers.
- **AI moderator.** "AI moderator engine", with `CLOUDFLARE_MODERATOR_MODEL` (`anthropic/claude-haiku-4.5`). Release covers are sent as image links, so the model must read images.
- **Not on Workers AI.** Stem splitting: Workers AI has no source separation model, so stems stay on Demucs or Suno.
- **In code.** `App\Services\Ai\Cloudflare\CloudflareClient` runs models (`ai/run`), and chats with language models. `AiSettings::ENGINES` lists which engines each switchable tool can use.

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
| Lyrics, Composer and AI moderator engines | Replicate |
| ElevenLabs Music credits per 10 seconds | 5 |
| MiniMax Music 2.5 credits per song | 30 |
| MiniMax Music 1.5 credits per song | 10 |
| Stable Audio 2.5 credits per track | 40 |
| ElevenLabs Music v2 credits per 10 seconds | 5 |
| MiniMax Music 2.6 credits per song | 30 |
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

## Genre and mood

New tracks and studio mixes are tagged with up to three moods, and get a suggested genre: the genre their audio sounds like. The creator's own genre is never changed; the app offers the suggestion when it differs.

- **The model.** CLAP (`laion/larger_clap_music_and_speech`, Apache-2.0) scores audio against text, so it can match any label without training. The music-only checkpoint on Hugging Face has a broken text tower (every text embeds the same), so it isn't used. Nobody hosts it on Replicate, so ours lives in `models/style-tagger` (Cog). Push it with the **Push style tagger** GitHub workflow (Actions → Push style tagger → Run), which needs the `REPLICATE_API_TOKEN` secret and creates the model on its first run. It is created public, so Replicate bills only the seconds it runs: a song takes about a second on CPU, well under a tenth of a cent.
- **Turning it on.** At `/admin/ai`, set "Genre and Mood Model" to the pushed model (like `toneflix/style-tagger`). "Detect Genre and Mood" is on by default but does nothing until the model is set and `REPLICATE_API_TOKEN` is in `.env`. The app pays for these calls, not creators.
- **When it runs.** The same moments as tempo and key: new audio on a track, or a new preview mix on a project track, queues `TagStyle`. Tracks released from the studio take their project track's tags, and a project track's tags pass to its draft releases.
- **How it works.** FFmpeg cuts up to 120 seconds from the middle of the song. The model embeds it in 10 second windows, averages them, and scores the average against every genre and every mood, each group adding up to 1. The top genre is suggested when it scores at least 0.5. Moods scoring at least 0.15 are kept, strongest first.
- **Accuracy.** On 40 labelled GTZAN clips, the top genre out of our 54 was right 12 times; at 0.5 and above it was suggested for 13 clips and right for 8. Misses are mostly neighbours (rock as blues, metal as punk, reggae as reggaeton, jazz as classical), and old recordings lean toward Gospel and Soul. That is why the genre is only offered as "sounds like", never set. Moods read well on clear cases (metal as aggressive, ballads as romantic) but have no labelled set to measure against.
- **Genre prompts.** Genres are scored by "This is a {title} song." CLAP knows little about many African and regional genres by name, so these carry a short description instead (Genres page, "What the tagger listens for"; defaults in `database/data/genre-prompts.php`).
- **Lyrics.** With "Read Lyrics for Mood" on, the Composer writing model reads a track's lyrics and names up to three moods. Those count for 30% against the audio's 70%. New lyrics are read again without listening to the audio again.
- **Moods.** happy, sad, energetic, calm, romantic, dark, uplifting, aggressive, dreamy, melancholic, playful, epic, tense, nostalgic, groovy, sensual (`App\Enums\Mood`).
- **Corrections.** `PUT /api/creator/tracks/{track}/moods` or `PUT /api/creator/projects/{project}/tracks/{track}/moods` with `moods` (up to three). Moods set by hand are never replaced. Sending none goes back to the detected ones.
- **API.** Tracks and project tracks carry `styles`: `moods`, `source` (`detected` or `manual`) and `suggestedGenreId`. `GET /api/tracks?mood=happy,calm` returns tracks with any of those moods.
- **Older tracks.** Run `php artisan tags:styles` once after setting the model. `--all` listens again to everything.
- **Licensing.** The model code and weights are Apache-2.0 (the LAION-CLAP checkpoints are CC0). Essentia's genre and mood models were left out because they are CC BY-NC-SA, which rules out commercial use without a paid licence.

## In code

- `App\Services\Ai\AiStudio` starts, runs and fails jobs (`ai_jobs` table).
- `App\Services\Ai\Credits` spends, refunds and adds credits.
- Each task is an `AiTask` in `App\Services\Ai\Tasks` (`GenerateMusic`, `SplitStems`, `TranscribeLyrics`) and names the `AiEngine` it runs on.
- Stem splitting goes through a `StemSplitter` in `App\Services\Ai\Stems`: `DemucsSplitter` (Replicate) or `SunoSplitter`.
- `App\Services\Ai\Suno\SunoClient` starts Suno tasks, polls them, uploads and downloads files.
- Section edits go through `App\Services\Ai\Tasks\EditSection`, which plans the window with `EditWindow`, runs a `SectionEditor` and splices with `StemAudio::fit()` and `splice()`.
- `App\Services\Lyrics\Lyrics` saves lyrics and applies the explicit and review checks (`LyricsCheck`). `Transcriber` calls Whisper.
- `App\Services\Audio\AudioTagger` saves tempo and key tags. `Analysis\AudioAnalyzer` decodes audio for `TempoDetector` and `KeyDetector`.
- `App\Services\Audio\StyleTagger` saves moods and the suggested genre. `App\Services\Ai\Styles\StyleModel` cuts the excerpt and calls the model.
- `laravel/ai` has no Replicate driver, so a custom `replicate` driver is registered in `AppServiceProvider`, and Demucs is called through `ReplicateClient`.
