<?php

namespace App\Services\Video;

use App\Models\MusicVideo;

/**
 * Writes a song's timed lyric lines as an ASS subtitle file sized to the
 * video, one line on screen at a time, in one of the lyric styles.
 */
class LyricCaptions
{
    /** Font the lines are drawn in, shipped in resources/fonts */
    public const FONT = 'DejaVu Sans';

    /** Seconds a line stays up when nothing says when it ends */
    protected const HOLD = 4.0;

    /** Shortest time a line is shown */
    protected const MIN_SECONDS = 0.3;

    /**
     * The subtitle file for these lines, or null when none falls inside the
     * video. The caller removes the file.
     *
     * @param  list<array{start: float, end: ?float, text: string}>  $lines
     * @param  array{0: int, 1: int}  $size
     */
    public function write(array $lines, array $size, string $style, float $duration): ?string
    {
        $events = $this->events($lines, $style, $duration);

        if (! $events) {
            return null;
        }

        $file = tempnam(sys_get_temp_dir(), 'lyrics-');
        $ass = "{$file}.ass";

        @unlink($file);
        file_put_contents($ass, $this->header($size, $style) . implode("\n", $events) . "\n");

        return $ass;
    }

    /**
     * The folder FFmpeg loads the lyric font from.
     */
    public static function fontsDir(): string
    {
        return resource_path('fonts');
    }

    /**
     * @param  array{0: int, 1: int}  $size
     */
    protected function header(array $size, string $style): string
    {
        [$width, $height] = $size;
        $side = min($width, $height);
        $centered = $style === MusicVideo::CENTERED;
        $fontSize = (int) round($side * ($centered ? 0.085 : 0.062));
        $outline = max(2, (int) round($side * 0.005));
        $alignment = $centered ? 5 : 2;
        $marginX = (int) round($width * 0.08);
        $marginY = (int) round($height * 0.07);
        $font = self::FONT;

        return <<<ASS
        [Script Info]
        ScriptType: v4.00+
        PlayResX: {$width}
        PlayResY: {$height}
        WrapStyle: 0
        ScaledBorderAndShadow: yes

        [V4+ Styles]
        Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding
        Style: Lyric,{$font},{$fontSize},&H00FFFFFF,&H00FFFFFF,&H00000000,&H80000000,-1,0,0,0,100,100,0,0,1,{$outline},1,{$alignment},{$marginX},{$marginX},{$marginY},1

        [Events]
        Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text

        ASS;
    }

    /**
     * One dialogue event per line, each ending by the next line's start.
     *
     * @param  list<array{start: float, end: ?float, text: string}>  $lines
     * @return list<string>
     */
    protected function events(array $lines, string $style, float $duration): array
    {
        $events = [];
        $fade = $style === MusicVideo::CENTERED ? '{\\fad(150,150)}' : '';

        foreach (array_values($lines) as $i => $line) {
            $start = (float) $line['start'];
            $next = isset($lines[$i + 1]) ? (float) $lines[$i + 1]['start'] : $duration;
            $end = isset($line['end']) && $line['end'] > $start ? (float) $line['end'] : $start + self::HOLD;
            $end = min($end, $next, $duration);
            $text = $this->escape($line['text']);

            if ($start >= $duration || $end - $start < self::MIN_SECONDS || $text === '') {
                continue;
            }

            $events[] = 'Dialogue: 0,' . $this->clock($start) . ',' . $this->clock($end) . ",Lyric,,0,0,0,,{$fade}{$text}";
        }

        return $events;
    }

    /**
     * Text with the characters ASS reads as tags or breaks made plain.
     */
    protected function escape(string $text): string
    {
        $text = strtr($text, ['\\' => '/', '{' => '(', '}' => ')']);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Seconds as an ASS time, like 0:01:02.35.
     */
    protected function clock(float $seconds): string
    {
        $centiseconds = (int) round($seconds * 100);
        $hours = intdiv($centiseconds, 360000);
        $minutes = intdiv($centiseconds % 360000, 6000);
        $rest = ($centiseconds % 6000) / 100;

        return sprintf('%d:%02d:%05.2f', $hours, $minutes, $rest);
    }
}
