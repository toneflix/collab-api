<?php

namespace App\Services\Agent\Parts;

/**
 * Turns parts the AI wrote as beats into instrument stems the studio plays:
 * each part's loop becomes a pattern, repeated by clips through every
 * section it plays in. Saved like the studio's own instrument stems
 * (`meta.instrument`, version 2), with no audio file.
 */
class InstrumentParts
{
    /** The drum kit's voices, on General MIDI notes */
    public const DRUMS = ['kick' => 36, 'rim' => 37, 'snare' => 38, 'clap' => 39, 'hat' => 42, 'shaker' => 44, 'openhat' => 46, 'tom' => 47];

    /** The built-in synth's settings for each sound a part can ask for */
    public const SOUNDS = [
        'piano' => ['wave' => 'triangle', 'detune' => 0, 'attack' => 0.005, 'decay' => 0.6, 'sustain' => 0.25, 'release' => 0.3, 'cutoff' => 5000, 'resonance' => 1, 'envelope' => 0, 'volume' => 0.5],
        'pluck' => ['wave' => 'sawtooth', 'detune' => 0, 'attack' => 0.002, 'decay' => 0.18, 'sustain' => 0.05, 'release' => 0.12, 'cutoff' => 3200, 'resonance' => 1, 'envelope' => 1, 'volume' => 0.45],
        'pad' => ['wave' => 'sawtooth', 'detune' => 9, 'attack' => 0.35, 'decay' => 0.4, 'sustain' => 0.7, 'release' => 0.8, 'cutoff' => 1800, 'resonance' => 1, 'envelope' => 0, 'volume' => 0.35],
        'bass' => ['wave' => 'square', 'detune' => 0, 'attack' => 0.004, 'decay' => 0.2, 'sustain' => 0.6, 'release' => 0.08, 'cutoff' => 900, 'resonance' => 2, 'envelope' => 1, 'volume' => 0.5],
        'lead' => ['wave' => 'sawtooth', 'detune' => 6, 'attack' => 0.01, 'decay' => 0.15, 'sustain' => 0.6, 'release' => 0.15, 'cutoff' => 4200, 'resonance' => 2, 'envelope' => 1, 'volume' => 0.4],
    ];

    /** Most parts and notes a track keeps */
    public const MAX_PARTS = 5;

    public const MAX_NOTES = 512;

    /**
     * The tempo, and each part as a stem's title and `meta.instrument` JSON,
     * filling about `$seconds` of music. Parts without notes are left out.
     *
     * @param  array<string, mixed>  $answer
     * @return array{bpm: float, parts: list<array{title: string, instrument: string}>}
     */
    public function build(array $answer, int $seconds): array
    {
        $bpm = round(min(180, max(60, (float) ($answer['bpm'] ?? 100))), 1);
        $beat = 60 / $bpm;
        $sections = $this->sections($answer['sections'] ?? [], $seconds, $beat);
        $parts = [];

        foreach (array_slice(is_array($answer['parts'] ?? null) ? $answer['parts'] : [], 0, self::MAX_PARTS) as $i => $part) {
            if (! is_array($part)) {
                continue;
            }

            $drums = ($part['instrument'] ?? '') === 'drums';
            $name = str((string) ($part['name'] ?? '') ?: ($drums ? 'Drums' : 'Synth'))->limit(40, '')->toString();
            $bars = (int) min(8, max(1, (int) ($part['bars'] ?? 2)));
            $length = $bars * 4;
            $notes = $this->notes($part['notes'] ?? [], $drums, $length, $beat);

            if (! $notes) {
                continue;
            }

            $id = (string) ($part['id'] ?? $name);
            $clips = [];

            foreach ($sections as [$start, $end, $playing]) {
                if ($playing !== null && ! in_array($id, $playing, true) && ! in_array($name, $playing, true)) {
                    continue;
                }

                for ($at = $start; $at < $end - 0.01; $at += $length * $beat) {
                    $clips[] = [
                        'pattern' => 'p1',
                        'start' => round($at, 3),
                        'offset' => 0,
                        'duration' => round(min($length * $beat, $end - $at), 3),
                        'gain' => 1,
                    ];
                }
            }

            if (! $clips) {
                continue;
            }

            $sound = (string) ($part['sound'] ?? '');
            $instrument = $drums
                ? ['id' => 'drums']
                : ['id' => 'synth', 'state' => self::SOUNDS[$sound] ?? self::SOUNDS[$i === 0 ? 'bass' : 'piano']];

            $parts[] = [
                'title' => $name,
                'instrument' => json_encode([
                    'version' => 2,
                    'instrument' => $instrument,
                    'patterns' => ['p1' => ['name' => $name, 'notes' => $notes]],
                    'clips' => array_slice($clips, 0, 200),
                ]),
            ];
        }

        return ['bpm' => $bpm, 'parts' => $parts];
    }

    /**
     * Sections as [start, end, parts playing or null for all], in seconds,
     * filling the track. Without usable sections, one section plays everything.
     *
     * @return list<array{0: float, 1: float, 2: ?list<string>}>
     */
    protected function sections(mixed $list, int $seconds, float $beat): array
    {
        $out = [];
        $at = 0.0;

        foreach (is_array($list) ? $list : [] as $section) {
            if (! is_array($section) || $at >= $seconds) {
                continue;
            }

            $bars = (int) min(32, max(1, (int) ($section['bars'] ?? 4)));
            $end = min($seconds, $at + $bars * 4 * $beat);
            $playing = array_values(array_filter(array_map('strval', is_array($section['parts'] ?? null) ? $section['parts'] : [])));

            $out[] = [$at, $end, $playing ?: null];
            $at = $end;
        }

        if ($at < $seconds - 1) {
            $out[] = [$at, (float) $seconds, null];
        }

        return $out;
    }

    /**
     * A part's notes in seconds, kept inside its loop: drum hits on the kit's
     * voices, other notes on the piano's range.
     *
     * @return list<array{pitch: int, start: float, duration: float, velocity: float}>
     */
    protected function notes(mixed $list, bool $drums, int $length, float $beat): array
    {
        $notes = [];

        foreach (is_array($list) ? $list : [] as $note) {
            if (! is_array($note)) {
                continue;
            }

            $pitch = $drums && is_string($note['pitch'] ?? null)
                ? self::DRUMS[strtolower($note['pitch'])] ?? null
                : (is_numeric($note['pitch'] ?? null) ? (int) $note['pitch'] : null);
            $start = (float) ($note['beat'] ?? -1);
            $duration = $drums ? 0.25 : (float) ($note['length'] ?? 1);

            $valid = $pitch !== null
                && ($drums ? in_array($pitch, self::DRUMS, true) : $pitch >= 21 && $pitch <= 108)
                && $start >= 0
                && $start < $length
                && $duration > 0;

            if (! $valid) {
                continue;
            }

            $notes[] = [
                'pitch' => $pitch,
                'start' => round($start * $beat, 3),
                'duration' => round(min($duration, $length - $start) * $beat, 3),
                'velocity' => round(min(1, max(0.1, (float) ($note['velocity'] ?? 0.8))), 2),
            ];
        }

        usort($notes, fn(array $a, array $b) => $a['start'] <=> $b['start']);

        return array_slice($notes, 0, self::MAX_NOTES);
    }
}
