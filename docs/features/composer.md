# Composer

Composer is the songwriting notepad under Projects in the sidebar. This page covers its word tools (completion, rhymes, related words, definitions and syllable counts) and its AI suggestions.

## Word data

| Lookup | Datamuse (default) | Local dictionary |
| --- | --- | --- |
| Completion as you type | `/sug` | Dictionary words starting with the text |
| Perfect rhymes | `rel_rhy` | Same sounds from the last stressed vowel |
| Near rhymes | `rel_nry` | Same vowels from the last stressed vowel |
| Sounds like | `sl` | Same as near rhymes |
| Multi-syllable rhymes | Always local | Words whose last two or three vowels match the end of a phrase ("touch me" and "lovely") |
| Related words | `ml`, `lc`, `rel_trg` | None |
| Definitions | `md=d` | None |
| Syllable counts | Always local | CMU dictionary, or a guess from spelling for words it lacks |

Admins pick the source with the **Word Source** setting in the Composer section of `/admin/ai` (`composer_word_source`: `datamuse` or `local`). Datamuse is called only from the API, never from browsers. Each answer is cached for 7 days, and when Datamuse fails or is slow (5 second timeout) the local dictionary answers instead, without caching that answer.

Datamuse asks customer-facing apps to describe their use to them, and from January 1, 2027 it needs a key (`DATAMUSE_API_KEY`) and allows 100,000 requests a day per key. See [datamuse.com/api](https://www.datamuse.com/api/). Credit Datamuse wherever its results show.

## The local dictionary

The [CMU Pronouncing Dictionary](https://github.com/cmusphinx/cmudict) (2-clause BSD, about 135,000 words) ships in `database/data/cmudict`, with its licence. Load it once after migrating, and again after replacing the file:

```bash
php artisan words:import
php artisan words:import --path=/path/to/cmudict.dict   # another copy, plain or gzipped
```

It fills the `words` table in batches of 1,000 and keeps only the first pronunciation of each word. Running it again updates rows in place; `--if-empty` skips it when the table already has words, which is how the container start script runs it.

Local lists put common words first. Each word's `rank` is its [SCOWL](http://wordlist.aspell.net/) level (10 for the most common English, 99 when SCOWL lacks it), from `database/data/scowl` (permissive licence). See `database/data/README.md` for how that file was made.

## Endpoints

All need a signed-in user and are limited to 120 requests a minute. `limit` sets how many words come back.

| Method | Path | Returns |
| --- | --- | --- |
| GET | `/api/words/complete?q=tou` | Up to 10 words (max 25) |
| GET | `/api/words/{word}/rhymes?type=perfect` | `perfect`, `near`, `sounds` or `multi`; up to 60 (max 100). `{word}` may be a phrase for `multi` |
| GET | `/api/words/{word}/related?type=means` | `means`, `follows` or `triggers` |
| GET | `/api/words/{word}/definitions` | `{ partOfSpeech, text }` per meaning |
| POST | `/api/words/syllables` | `{ words: [...] }`, up to 200; each comes back with `syllables`, `stress` (such as `010`), `vowelKey` (words sharing it rhyme; dictionary words only) and `known` |

Lookup answers carry `source` (`datamuse` or `local`) beside `data`, so the app can show the Datamuse credit only when it applies.

## AI suggestions

Writers can ask for the next line, the next four lines (a block), a full verse, or other takes on a line. Each ask returns options (three lines, two blocks, one verse or three rewrites) that the writer picks from or dismisses.

```
POST /api/creator/songs/{song}/suggestions
{ "kind": "line", "line": 12, "body": "...", "instruction": "make it about leaving home" }
```

- `kind` is `line`, `block`, `verse` or `rewrite`. `line` is the cursor's line, counted from 1: an empty line is filled, and new lines go after a line with words. `rewrite` rewrites that line.
- `body` is the text in the editor, so unsaved typing counts. Without it the saved lyrics are used. `instruction` is an optional note to the model (up to 200 characters).
- The answer has `options` (each `{ lines: [...] }`), the `credits` spent and the writer's `balance`. Asks are limited to 30 a minute.

Suggestions need the **AI studio tools** plan feature (`ai_tools`, on Premium by default) and credits. Each kind has its own cost on `/admin/ai` (`ai_composer_line_credits` 1, `ai_composer_block_credits` 1, `ai_composer_verse_credits` 2, `ai_composer_rewrite_credits` 1). When the model fails or every option is dropped, the credits go back.

The model is Claude Haiku on Replicate by default (**Composer Writing Model**, `ai_composer_model`, starting from `REPLICATE_COMPOSER_MODEL`), with the same `REPLICATE_API_TOKEN` as the other AI tools. It sees the song's title, mood, language, tempo, about 60 lines before the cursor and 12 after, and the typical syllable count of nearby lines. It is told to write original lines only and to keep songs not marked explicit clean. Options with words on the lyrics review list are dropped, and so are options with explicit words on songs not marked explicit.

Before a block or verse suggestion the song is saved as a `before_ai` version, so the writer can go back.

**Composer Song Model** (`ai_composer_music_model`, MiniMax Music 2.5 by default) is the music model that sings songs made from Composer lyrics. Only models that take lyrics can be picked (MiniMax 2.5, MiniMax 1.5 and Suno).

## Song to project

A song can become a project with one track that carries its lyrics.

```
POST /api/creator/songs/{song}/project
{ "genre_id": 3, "artist_id": 12, "title": "Late Night Train", "privacy": "private", "generate": true, "prompt": "Moody synth pop, female vocals" }
```

- The project and track take the song's title unless `title` is given. Both start private unless `privacy` says otherwise. The project counts toward the `max_projects` plan limit.
- The song's sung lines (section names left out) go on the track's lyric sheet as written lyrics. The song keeps a link to the track (`project` in the song resource, with the project's `id`, `slug`, `title` and the `trackId`).
- With `generate`, AI sings the lyrics onto the track with the **Composer Song Model**, the section names included. Models that take a length get `seconds`, 180 by default. `prompt` (the sound) is then required. Access, the model's lyrics length and the credits are all checked before anything is created, so a failed check leaves no empty project behind. The started job comes back as `aiJob` beside `data`.
- A song makes one project. `POST /api/creator/songs/{song}/project/lyrics` puts the song's current lyrics on that track's lyric sheet again. It needs edit access to the track.
