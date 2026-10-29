<?php

namespace App\Services\Composer\Ai;

use App\Enums\HttpStatus;
use App\Models\Song;
use App\Models\SongVersion;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Credits;
use App\Services\Composer\Songs;
use App\Services\Composer\Words\Words;
use App\Services\Lyrics\LyricsCheck;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Writes lyric suggestions for a song: the next line, a block of lines, a
 * verse, or other takes on a line. Each ask costs credits, refunded when the
 * model fails.
 */
class LyricWriter
{
    public const LINE = 'line';

    public const BLOCK = 'block';

    public const VERSE = 'verse';

    public const REWRITE = 'rewrite';

    /**
     * Each kind of ask: how many options come back and how many lines each has.
     *
     * @var array<string, array{options: int, lines: int}>
     */
    public const KINDS = [
        self::LINE => ['options' => 3, 'lines' => 1],
        self::BLOCK => ['options' => 2, 'lines' => 4],
        self::VERSE => ['options' => 1, 'lines' => 16],
        self::REWRITE => ['options' => 3, 'lines' => 1],
    ];

    /** Lines of the song before the writing spot the model sees */
    protected const LINES_BEFORE = 60;

    /** Lines after the writing spot the model sees */
    protected const LINES_AFTER = 12;

    public function __construct(
        protected LyricModel $model,
        protected Songs $songs,
        protected Words $words,
        protected LyricsCheck $check,
    ) {}

    /**
     * Seconds to wait for an answer of up to `$maxTokens`: a minute for short
     * asks, longer for long ones.
     */
    public static function timeout(int $maxTokens): int
    {
        return $maxTokens > 1200 ? max(180, intdiv($maxTokens, 40)) : 60;
    }

    /**
     * Ask for suggestions at a line of the lyrics, counted from 1.
     *
     * @return array{kind: string, line: int, options: list<list<string>>, credits: int, balance: int}
     */
    public function suggest(Song $song, User $user, string $kind, string $body, int $line, ?string $instruction = null): array
    {
        $credits = Credits::for($user);
        $credits->ensureAccess('kind');

        if (! $this->model->available()) {
            throw ValidationException::withMessages(['kind' => 'AI writing isn\'t set up yet.']);
        }

        $lines = preg_split('/\R/u', $body) ?: [''];
        $at = min(max(1, $line), count($lines)) - 1;
        $cost = AiSettings::composerCredits($kind);
        $spend = $credits->spend($cost, 'Composer: ' . $kind . ' suggestion', $song, 'kind');

        try {
            $answer = $this->model->ask($this->system($song), $this->prompt($song, $kind, $lines, $at, $instruction));
            $options = $this->options($answer, $kind, (bool) $song->editorSettings()['explicit']);
        } catch (Throwable $e) {
            $credits->refund($spend, 'Composer suggestion failed');
            report($e);

            abort(HttpStatus::BAD_GATEWAY->value, 'No suggestion this time. Credits refunded.');
        }

        if ($options === []) {
            $credits->refund($spend, 'Composer suggestion failed');

            abort(HttpStatus::BAD_GATEWAY->value, 'No suggestion this time. Credits refunded.');
        }

        if (in_array($kind, [self::BLOCK, self::VERSE], true)) {
            $this->songs->snapshot($song, $user, SongVersion::BEFORE_AI);
        }

        return [
            'kind' => $kind,
            'line' => $at + 1,
            'options' => $options,
            'credits' => $cost,
            'balance' => $credits->total(),
        ];
    }

    protected function system(Song $song): string
    {
        $language = $song->editorSettings()['explicit']
            ? 'Strong language is fine where it fits the song.'
            : 'Keep it clean: no profanity, slurs or sexual content.';

        return implode("\n", [
            'You co-write lyrics inside a songwriter\'s notepad.',
            'Write original lines only. Never quote or closely paraphrase existing songs.',
            'Keep the writer\'s voice: their perspective, tense, slang, imagery and rhyme scheme.',
            'Match the rhythm of nearby lines: about the same number of syllables, and land the rhyme on the last word where the scheme calls for one.',
            'Lines in square brackets on their own, like [Verse 1] or [Hook], name sections. Only write one when asked for a verse.',
            $language,
            'Answer with JSON only, no other text: {"options": [{"lines": ["first line", "second line"]}]}',
        ]);
    }

    /**
     * @param  list<string>  $lines
     */
    protected function prompt(Song $song, string $kind, array $lines, int $at, ?string $instruction): string
    {
        $settings = $song->editorSettings();
        $shape = self::KINDS[$kind];
        $current = trim($lines[$at] ?? '');

        // A line with words is kept and new lines go after it; an empty line is filled.
        $fill = $kind === self::REWRITE || $current === '';
        $before = array_slice($lines, max(0, $at - self::LINES_BEFORE), min($at, self::LINES_BEFORE) + ($fill ? 0 : 1));
        $after = array_slice($lines, $at + 1, self::LINES_AFTER);
        $marker = $kind === self::REWRITE ? ">>> REWRITE THIS LINE: {$current} <<<" : '>>> WRITE HERE <<<';

        $task = match ($kind) {
            self::LINE => 'Write the next line at the marker.',
            self::BLOCK => 'Write the next ' . $shape['lines'] . ' lines at the marker.',
            self::VERSE => 'Write a full verse of 8 to ' . $shape['lines'] . ' lines at the marker, starting with a section line like [Verse 2] that follows the song\'s numbering.',
            self::REWRITE => 'Write different takes on the marked line. Keep its meaning and place in the rhyme scheme, and change the words.',
        };

        $syllables = $this->syllableHint(array_merge($before, $after));

        return implode("\n", array_filter([
            'Song: ' . ($song->title ?: 'Untitled'),
            $settings['mood'] ? 'Mood: ' . $settings['mood'] : null,
            $settings['language'] ? 'Language: ' . $settings['language'] : null,
            $song->bpm ? 'Tempo: ' . $song->bpm . ' BPM' : null,
            $syllables ? "Nearby lines have about {$syllables} syllables." : null,
            '',
            'Lyrics so far:',
            '"""',
            ...$before,
            $marker,
            ...$after,
            '"""',
            '',
            $task . ' Give ' . $shape['options'] . ' ' . ($shape['options'] === 1 ? 'option' : 'different options') . '.',
            $instruction ? 'The writer asks: ' . $instruction : null,
        ], fn($line) => $line !== null));
    }

    /**
     * The typical syllable count of the sung lines closest to the writing spot.
     *
     * @param  list<string>  $lines
     */
    protected function syllableHint(array $lines): ?int
    {
        $sung = collect($lines)
            ->map(fn(string $line) => trim($line))
            ->reject(fn(string $line) => $line === '' || preg_match('/^\[[^\]]*\]$/u', $line))
            ->take(-6)
            ->values();

        if ($sung->isEmpty()) {
            return null;
        }

        $words = $sung
            ->flatMap(fn(string $line) => $this->wordsOf($line))
            ->unique()
            ->take(200)
            ->values()
            ->all();
        $counts = collect($this->words->syllables($words))
            ->mapWithKeys(fn(array $sound) => [$sound['word'] => $sound['syllables']]);

        $median = $sung
            ->map(fn(string $line) => collect($this->wordsOf($line))->sum(fn(string $word) => $counts[$word] ?? 1))
            ->median();

        return $median ? (int) round($median) : null;
    }

    /**
     * @return list<string>
     */
    protected function wordsOf(string $line): array
    {
        preg_match_all("/[\p{L}\p{N}]+(?:'[\p{L}\p{N}]+)*/u", mb_strtolower($line), $matches);

        return $matches[0];
    }

    /**
     * The options in the model's answer, trimmed to the kind's shape. Options
     * that hit the review list, or explicit words on a clean song, are dropped.
     *
     * @return list<list<string>>
     */
    protected function options(string $answer, string $kind, bool $explicit): array
    {
        $json = str($answer)
            ->after('```json')
            ->before('```')
            ->trim()
            ->toString();
        $start = strpos($json, '{');
        $end = strrpos($json, '}');
        $data = $start !== false && $end !== false ? json_decode(substr($json, $start, $end - $start + 1), true) : null;
        $shape = self::KINDS[$kind];

        return collect(is_array($data['options'] ?? null) ? $data['options'] : [])
            ->map(fn($option) => collect(is_array($option['lines'] ?? null) ? $option['lines'] : [])
                ->filter(fn($line) => is_string($line))
                ->map(fn(string $line) => mb_substr(trim(preg_replace('/\s+/u', ' ', $line)), 0, 200))
                ->reject(fn(string $line) => $line === '' || ($kind !== self::VERSE && preg_match('/^\[[^\]]*\]$/u', $line)))
                ->take($shape['lines'] + ($kind === self::VERSE ? 1 : 0))
                ->values()
                ->all())
            ->filter()
            ->reject(function (array $lines) use ($explicit) {
                $found = $this->check->scan(implode("\n", $lines));

                return $found['review'] !== [] || (! $explicit && $found['explicit'] !== []);
            })
            ->unique(fn(array $lines) => mb_strtolower(implode("\n", $lines)))
            ->take($shape['options'])
            ->values()
            ->all();
    }
}
