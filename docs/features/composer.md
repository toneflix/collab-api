# Composer

Composer is the songwriting notepad under Projects in the sidebar. This page covers its word tools: completion, rhymes, related words, definitions and syllable counts.

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

Admins pick the source with the **Word Source** setting (`composer_word_source`: `datamuse` or `local`). Datamuse is called only from the API, never from browsers. Each answer is cached for 7 days, and when Datamuse fails or is slow (5 second timeout) the local dictionary answers instead, without caching that answer.

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
| POST | `/api/words/syllables` | `{ words: [...] }`, up to 200; each comes back with `syllables`, `stress` (such as `010`) and `known` |

Lookup answers carry `source` (`datamuse` or `local`) beside `data`, so the app can show the Datamuse credit only when it applies.
