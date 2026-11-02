<?php

namespace App\Services\Agent\Actions;

use App\Models\AgentStep;
use App\Models\ProjectTrack;
use App\Services\Agent\AgentStepFailed;
use App\Services\Agent\AgentWriter;
use App\Services\Agent\Parts\InstrumentParts;

/**
 * Writes an instrumental track as notes instead of audio: the AI writes
 * drum, bass and keys parts, which become instrument stems the studio plays
 * and creators can edit.
 */
class WriteParts implements AgentAction
{
    public function __construct(
        protected AgentWriter $writer,
        protected InstrumentParts $parts,
    ) {}

    public function advance(AgentStep $step): bool
    {
        if (! empty($step->output['stem_ids'])) {
            return true;
        }

        $input = $step->input;
        $track = ProjectTrack::query()->findOrFail($step->from($input['source'], 'project_track_id'));
        $seconds = (int) $input['seconds'];

        $answer = $this->writer->json($step->run, 'instrument parts', $this->system(), $this->prompt($input, $seconds), 3500);
        $built = $this->parts->build($answer, $seconds);

        if (! $built['parts']) {
            throw new AgentStepFailed('Parts: the AI didn\'t write any notes for this track.');
        }

        $track->meta = [...collect($track->meta ?? [])->all(), 'bpm' => $built['bpm'], 'timeSignature' => '4/4'];
        $track->save();

        $ids = [];

        foreach ($built['parts'] as $order => $part) {
            $stem = $track
                ->stems()
                ->create([
                    'title' => $part['title'],
                    'user_id' => $step->run->owner->id,
                    'meta' => ['name' => $part['title'], 'instrument' => $part['instrument'], 'gain' => 0.8, 'order' => $order],
                ]);

            $ids[] = $stem->id;
        }

        $step->remember(['stem_ids' => $ids, 'bpm' => $built['bpm']]);

        return true;
    }

    protected function system(): string
    {
        $drums = implode(', ', array_keys(InstrumentParts::DRUMS));
        $sounds = implode(', ', array_keys(InstrumentParts::SOUNDS));

        return <<<TEXT
        You are a music producer writing an instrumental as MIDI-style parts for a simple studio.
        Answer with JSON only:
        {"bpm": number, "parts": [{"id": string, "name": string, "instrument": "drums" | "synth", "sound": string, "bars": 1-8, "notes": [{"pitch": ..., "beat": number, "length": number, "velocity": 0-1}]}], "sections": [{"name": string, "bars": number, "parts": [part ids]}]}
        - 4/4 time. "beat" counts quarter notes from the start of the part's loop (0 up to bars * 4), and may be fractional (0.5 = an eighth note).
        - Drum parts use "pitch" names from: {$drums}. Their "length" is ignored.
        - Synth parts use MIDI note numbers for "pitch" (bass 28-52, chords and keys 48-76, leads 60-88) and a "sound" from: {$sounds}.
        - Write 3 or 4 parts: drums, a bass line, chords or keys, and optionally a lead melody, all in one key and fitting together.
        - Each part is a loop of 1 to 4 bars that repeats; keep it musical and idiomatic for the style.
        - Sections arrange the loops (intro, verse, chorus, breakdown, outro) by naming the part ids that play.
        TEXT;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function prompt(array $input, int $seconds): string
    {
        return collect([
            'Track: ' . ($input['title'] ?? ''),
            'Style: ' . ($input['style'] ?? ''),
            'Mood: ' . ($input['mood'] ?? ''),
            'About: ' . ($input['theme'] ?? ''),
            "Length: about {$seconds} seconds; choose a tempo and sections that fill it.",
        ])
            ->filter(fn(string $line) => ! str_ends_with($line, ': '))
            ->implode("\n");
    }
}
