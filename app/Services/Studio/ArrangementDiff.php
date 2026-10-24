<?php

namespace App\Services\Studio;

use Illuminate\Support\Arr;

/**
 * What changed between two snapshots of a track's arrangement, as a list of
 * changes and a short sentence ("moved Bass and set the tempo to 128 BPM").
 */
class ArrangementDiff
{
    /** Numbers closer than this count as the same */
    protected const TOLERANCE = 0.0005;

    /**
     * What each kind of stem edit reads as, before the stem's name.
     */
    protected const LABELS = [
        'moved' => 'moved',
        'trimmed' => 'trimmed',
        'split' => 'split or joined the clips of',
        'volume' => 'changed the volume of',
        'pan' => 'panned',
        'muted' => 'muted',
        'unmuted' => 'unmuted',
        'solo' => 'changed solo on',
        'fades' => 'changed the fades on',
        'effects' => 'changed the effects on',
        'pattern' => 'changed the pattern of',
        'audio' => 'replaced the audio of',
    ];

    /** @var array<int, array<string, mixed>> */
    protected array $changes;

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function __construct(protected array $before, protected array $after)
    {
        $this->changes = [...$this->trackChanges(), ...$this->stemChanges()];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    public static function between(?array $before, array $after): self
    {
        return new self($before ?? ['stems' => []], $after);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function changes(): array
    {
        return $this->changes;
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    /**
     * The changes as one short sentence, without a subject.
     */
    public function summary(int $limit = 3): string
    {
        $phrases = array_map(fn (array $change) => $this->phrase($change), $this->changes);
        $shown = array_slice($phrases, 0, $limit);
        $more = count($phrases) - count($shown);

        if ($more > 0) {
            $shown[] = $more === 1 ? '1 more change' : "{$more} more changes";
        }

        return str(Arr::join($shown, ', ', ' and '))->limit(250)->toString();
    }

    /**
     * @param  array<string, mixed>  $change
     */
    protected function phrase(array $change): string
    {
        return match ($change['type']) {
            'tempo' => "set the tempo to {$change['to']} BPM",
            'meter' => "changed the meter to {$change['to']}",
            'title' => "renamed the track to \"{$change['to']}\"",
            'added' => "added {$change['title']}",
            'removed' => "removed {$change['title']}",
            'renamed' => "renamed {$change['from']} to {$change['title']}",
            default => Arr::join(
                array_map(fn (string $what) => self::LABELS[$what] ?? $what, $change['what']),
                ', ',
                ' and '
            )." {$change['title']}",
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function trackChanges(): array
    {
        $changes = [];

        if (array_key_exists('bpm', $this->before) && $this->differs($this->before['bpm'] ?? null, $this->after['bpm'] ?? null)) {
            $changes[] = ['type' => 'tempo', 'from' => $this->before['bpm'] ?? null, 'to' => $this->after['bpm'] ?? null];
        }

        if (array_key_exists('timeSignature', $this->before) && ($this->before['timeSignature'] ?? null) !== ($this->after['timeSignature'] ?? null)) {
            $changes[] = ['type' => 'meter', 'from' => $this->before['timeSignature'] ?? null, 'to' => $this->after['timeSignature'] ?? null];
        }

        if (array_key_exists('title', $this->before) && ($this->before['title'] ?? null) !== ($this->after['title'] ?? null)) {
            $changes[] = ['type' => 'title', 'from' => $this->before['title'] ?? null, 'to' => $this->after['title'] ?? null];
        }

        return $changes;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function stemChanges(): array
    {
        $before = collect($this->before['stems'] ?? [])->keyBy('id');
        $after = collect($this->after['stems'] ?? [])->keyBy('id');
        $changes = [];

        foreach ($after as $id => $stem) {
            $old = $before->get($id);
            $title = $this->title($stem);

            if (! $old) {
                $changes[] = ['type' => 'added', 'stem_id' => $id, 'title' => $title];

                continue;
            }

            if ($this->title($old) !== $title) {
                $changes[] = ['type' => 'renamed', 'stem_id' => $id, 'title' => $title, 'from' => $this->title($old)];
            }

            $what = $this->stemEdits((array) ($old['meta'] ?? []), (array) ($stem['meta'] ?? []), $old['src'] ?? null, $stem['src'] ?? null);

            if ($what) {
                $changes[] = ['type' => 'edited', 'stem_id' => $id, 'title' => $title, 'what' => $what];
            }
        }

        foreach ($before as $id => $stem) {
            if (! $after->has($id)) {
                $changes[] = ['type' => 'removed', 'stem_id' => $id, 'title' => $this->title($stem)];
            }
        }

        return $changes;
    }

    /**
     * The kinds of edits made to one stem.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<int, string>
     */
    protected function stemEdits(array $old, array $new, ?string $oldSrc, ?string $newSrc): array
    {
        $what = [];
        $oldClips = $this->clips($old);
        $newClips = $this->clips($new);

        if (($old['instrument'] ?? null) !== ($new['instrument'] ?? null)) {
            $what[] = 'pattern';
        } elseif ($oldSrc !== $newSrc) {
            $what[] = 'audio';
        }

        if (count($oldClips) !== count($newClips)) {
            $what[] = 'split';
        } else {
            $pairs = array_map(null, $oldClips, $newClips);

            if ($this->anyDiffers($pairs, 'start')) {
                $what[] = 'moved';
            }

            if ($this->anyDiffers($pairs, 'offset') || $this->anyDiffers($pairs, 'duration')) {
                $what[] = 'trimmed';
            }

            if ($this->anyDiffers($pairs, 'fadeIn') || $this->anyDiffers($pairs, 'fadeOut')) {
                $what[] = 'fades';
            }

            if ($this->anyDiffers($pairs, 'gain')) {
                $what[] = 'volume';
            }
        }

        if ($this->differs($old['gain'] ?? 1, $new['gain'] ?? 1) && ! in_array('volume', $what)) {
            $what[] = 'volume';
        }

        if ($this->differs($old['stereoPan'] ?? 0, $new['stereoPan'] ?? 0)) {
            $what[] = 'pan';
        }

        $wasMuted = filter_var($old['muted'] ?? false, FILTER_VALIDATE_BOOL);
        $isMuted = filter_var($new['muted'] ?? false, FILTER_VALIDATE_BOOL);
        if ($wasMuted !== $isMuted) {
            $what[] = $isMuted ? 'muted' : 'unmuted';
        }

        if (filter_var($old['soloed'] ?? false, FILTER_VALIDATE_BOOL) !== filter_var($new['soloed'] ?? false, FILTER_VALIDATE_BOOL)) {
            $what[] = 'solo';
        }

        if (($old['effects'] ?? null) !== ($new['effects'] ?? null)) {
            $what[] = 'effects';
        }

        return $what;
    }

    /**
     * A stem's clips. Stems saved by the old editor have one clip, described
     * by `start`, `cuein`, `cueout` and the fades.
     *
     * @param  array<string, mixed>  $meta
     * @return array<int, array<string, float>>
     */
    protected function clips(array $meta): array
    {
        if (! empty($meta['clips']) && is_array($meta['clips'])) {
            return array_map(fn ($clip) => [
                'start' => (float) ($clip['start'] ?? 0),
                'offset' => (float) ($clip['offset'] ?? 0),
                'duration' => (float) ($clip['duration'] ?? 0),
                'gain' => (float) ($clip['gain'] ?? 1),
                'fadeIn' => (float) ($clip['fadeIn'] ?? 0),
                'fadeOut' => (float) ($clip['fadeOut'] ?? 0),
            ], array_values($meta['clips']));
        }

        $cuein = (float) ($meta['cuein'] ?? 0);
        $cueout = (float) ($meta['cueout'] ?? 0);

        return [[
            'start' => (float) ($meta['start'] ?? 0),
            'offset' => $cuein,
            'duration' => $cueout > 0 ? $cueout - $cuein : 0,
            'gain' => 1,
            'fadeIn' => (float) ($meta['fadeIn']['duration'] ?? 0),
            'fadeOut' => (float) ($meta['fadeOut']['duration'] ?? 0),
        ]];
    }

    /**
     * @param  array<int, array{0: array<string, float>, 1: array<string, float>}>  $pairs
     */
    protected function anyDiffers(array $pairs, string $key): bool
    {
        foreach ($pairs as [$old, $new]) {
            if ($this->differs($old[$key], $new[$key])) {
                return true;
            }
        }

        return false;
    }

    protected function differs(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) > self::TOLERANCE;
        }

        return $a !== $b;
    }

    /**
     * @param  array<string, mixed>  $stem
     */
    protected function title(array $stem): string
    {
        return (string) (($stem['title'] ?? '') ?: 'Stem '.($stem['id'] ?? ''));
    }
}
