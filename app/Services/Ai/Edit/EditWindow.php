<?php

namespace App\Services\Ai\Edit;

use Illuminate\Validation\ValidationException;

/**
 * Where a section edit happens in a stem's audio file, in seconds: the part
 * people picked, the part the engine writes again around it (with room for
 * crossfades and the engine's shortest section), and the clip sent to the
 * engine so it hears what comes before and after.
 */
final class EditWindow
{
    /** Length of the crossfades at each end of the picked part */
    public const FADE = 0.06;

    /** Seconds of audio sent on each side of the rewritten part, when the stem has them */
    public const CONTEXT = 10;

    public function __construct(
        public readonly float $start,
        public readonly float $end,
        public readonly float $infillStart,
        public readonly float $infillEnd,
        public readonly float $clipStart,
        public readonly float $clipEnd,
        public readonly float $duration,
    ) {}

    /**
     * Plan an edit of a part of an audio file this long, or explain why the
     * engine can't do it.
     */
    public static function plan(float $start, float $end, float $duration, SectionEditor $editor): self
    {
        if ($end > $duration + 0.05) {
            throw ValidationException::withMessages(['end' => 'The selection runs past the end of the stem\'s audio.']);
        }

        $end = min($end, $duration);
        $min = $editor->minSeconds();
        $minimum = $min / $editor->maxShare();

        if ($duration < $minimum) {
            throw ValidationException::withMessages([
                'start' => 'This stem is too short to edit with AI. It needs at least ' . self::seconds($minimum) . ' of audio.',
            ]);
        }

        [$infillStart, $infillEnd] = self::grow(max(0, $start - self::FADE), min($duration, $end + self::FADE), $min, $duration);

        $length = $infillEnd - $infillStart;
        [$clipStart, $clipEnd] = self::grow(
            $infillStart,
            $infillEnd,
            max($length + 2 * self::CONTEXT, $length / $editor->maxShare()),
            $duration,
        );

        if ($length > ($clipEnd - $clipStart) * $editor->maxShare() + 0.01) {
            throw ValidationException::withMessages([
                'end' => 'Select a shorter part. AI can rewrite at most ' . self::seconds(($duration * $editor->maxShare()) - 2 * self::FADE) . ' of this stem.',
            ]);
        }

        return new self($start, $end, $infillStart, $infillEnd, $clipStart, $clipEnd, $duration);
    }

    public function clipLength(): float
    {
        return $this->clipEnd - $this->clipStart;
    }

    public function infillLength(): float
    {
        return $this->infillEnd - $this->infillStart;
    }

    /**
     * Widen a span to at least a length, evenly where the audio allows,
     * keeping it inside the file and on whole hundredths of a second.
     *
     * @return array{0: float, 1: float}
     */
    protected static function grow(float $from, float $to, float $length, float $duration): array
    {
        $need = max(0, $length - ($to - $from));
        $from -= $need / 2;
        $to += $need / 2;

        if ($from < 0) {
            $to -= $from;
            $from = 0;
        }

        if ($to > $duration) {
            $from -= $to - $duration;
            $to = $duration;
        }

        return [max(0, floor($from * 100) / 100), min($duration, ceil($to * 100) / 100)];
    }

    protected static function seconds(float $seconds): string
    {
        return rtrim(rtrim(number_format(max(0, $seconds), 1), '0'), '.') . ' seconds';
    }
}
