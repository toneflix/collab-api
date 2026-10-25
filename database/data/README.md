# Word data for Composer

`php artisan words:import` loads these into the `words` table.

| Folder | Source | Licence | Used for |
| --- | --- | --- | --- |
| `cmudict` | [CMU Pronouncing Dictionary](https://github.com/cmusphinx/cmudict) `cmudict.dict`, gzipped | 2-clause BSD (`cmudict/LICENSE`) | Pronunciations, syllables, stress and rhyme keys |
| `scowl` | [SCOWL](http://wordlist.aspell.net/) 2020.12.07 | Permissive (`scowl/Copyright`) | `rank`: how common a word is, so common words come first |

`scowl/ranks.txt.gz` holds one `word<TAB>level` line per word, taken from SCOWL's `final/english-*` and `final/american-*` words, contractions and upper lists at levels 10 to 70. Level 10 is the most common English; a word in several lists keeps its lowest level. Words missing from it rank 99.
