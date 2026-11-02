<?php

namespace App\Services\Studio\Mix;

use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use App\Services\Audio\StemAudio;
use App\Services\Studio\Mix\Effects\MixEffect;
use App\Services\Studio\StemFiles;

/**
 * Mixes a project track's stems with FFmpeg, the way they sound in the
 * studio: clips (the later one on top where they overlap, and clips moved
 * off their own stem), clip gain and fades, effects, volume, pan, mute, solo
 * and the master volume. A stem with a plugin FFmpeg can't run plays from the
 * audio the studio printed through it. The mix is turned down to just under full scale when
 * it would clip, and encoded once as a 320 kbps MP3.
 */
class MixRenderer
{
    /** Highest peak a mix may reach, in dBFS */
    public const CEILING = -0.1;

    public function __construct(
        protected StemFiles $files,
        protected StemAudio $audio,
    ) {}

    /**
     * Render the mix to a local MP3, or give null when nothing can be heard.
     */
    public function render(ProjectTrack $track): ?string
    {
        $stems = $track
            ->stems()
            ->get()
            ->map(fn(ProjectTrackStem $stem) => [$stem, MixStem::fromMeta(collect($stem->meta)->all())]);
        $soloed = $stems->contains(fn(array $item) => $item[1]->soloed);
        $audible = $stems->filter(fn(array $item) => $soloed ? $item[1]->soloed : ! $item[1]->muted);

        $graph = new MixGraph;
        $pads = [];
        $length = 0.0;
        $byId = $stems->mapWithKeys(fn(array $item) => [(string) $item[0]->id => $item[0]]);
        $paths = [];
        // A stem's audio as a local file, copied once even when several tracks play from it
        $pathOf = function (ProjectTrackStem $stem) use (&$paths, $graph): ?string {
            $key = (string) $stem->id;

            if (! array_key_exists($key, $paths)) {
                $path = $this->files->local($stem);
                $paths[$key] = $path ? $graph->own($path) : null;
            }

            return $paths[$key];
        };

        try {
            foreach ($audible as [$stem, $mix]) {
                $printed = $mix->print ? $this->files->localFile($mix->print['file']) : null;

                if ($printed) {
                    $graph->own($printed);
                    $sources = [(string) $stem->id => $printed];
                    $mix = $mix->printed();
                } else {
                    $sources = collect($mix->clips)
                        ->map(fn(array $clip) => $clip['source'] ?? (string) $stem->id)
                        ->unique()
                        ->mapWithKeys(fn(string $id) => [$id => $byId->has($id) ? $pathOf($byId->get($id)) : null])
                        ->filter()
                        ->all();
                }

                $pad = $this->stem($graph, (string) $stem->id, $sources, $mix, $length);

                if ($pad) {
                    $pads[] = $pad;
                }
            }

            if (! $pads || $length <= 0) {
                return null;
            }

            $master = count($pads) > 1
                ? $graph->chain($pads, 'amix=inputs=' . count($pads) . ':normalize=0:duration=longest', 'mix')
                : $pads[0];
            $master = $graph->chain($master, 'volume=' . round(self::masterVolume($track), 4), 'vol');

            foreach ($this->masterEffects($track) as $effect) {
                $master = $effect->apply($graph, $master);
            }

            $master = $graph->chain($master, 'atrim=end=' . round($length, 4), 'out');

            $wav = $graph->own($this->audio->wav($graph->arguments($master)));
            $peak = $this->audio->peak($wav);

            return $this->audio->mp3($wav, $peak !== null && $peak > self::CEILING ? self::CEILING - $peak : 0.0);
        } finally {
            $graph->cleanup();
        }
    }

    /**
     * The track's master volume, 0 to 1, saved by the studio.
     */
    public static function masterVolume(ProjectTrack $track): float
    {
        $volume = $track->meta['mixVolume'] ?? 1;

        return is_numeric($volume) ? max(0, min(1, (float) $volume)) : 1.0;
    }

    /**
     * The effects on the master bus, after the master volume as in the studio.
     *
     * @return list<MixEffect>
     */
    protected function masterEffects(ProjectTrack $track): array
    {
        return MixStem::fromMeta(['effects' => $track->meta['masterEffects'] ?? null])->mixEffects(2);
    }

    /**
     * Add one stem to the graph and return its pad, or null when none of its
     * clips play. A clip plays from its own stem's audio, or from the stem it
     * was moved off; where clips overlap only the later one is heard. Stretches
     * the mix length to the stem's last clip.
     *
     * @param  array<string, string>  $sources  Local audio files by stem id
     */
    protected function stem(MixGraph $graph, string $id, array $sources, MixStem $mix, float &$length): ?string
    {
        $info = array_map(fn(string $path) => $this->audio->probe($path), $sources);
        $clips = [];

        foreach ($mix->clips as $clip) {
            $source = $clip['source'] ?? $id;

            if (! isset($info[$source])) {
                continue;
            }

            foreach ($this->playable([$clip], $info[$source]['duration']) as $playable) {
                $clips[] = [...$playable, 'source' => $source];
            }
        }

        $parts = MixStem::audible($clips);

        if (! $parts) {
            return null;
        }

        $used = array_intersect_key($info, array_flip(array_column($clips, 'source')));
        $channels = max(array_column($used, 'channels'));
        $layout = $channels === 1 ? 'mono' : 'stereo';
        $pads = [];

        foreach (collect($parts)->groupBy(fn(array $part) => $part['clip']['source']) as $source => $group) {
            $input = $graph->chain(
                $graph->input($sources[$source]),
                'aresample=' . MixGraph::RATE . ',aformat=sample_fmts=fltp:channel_layouts=' . $layout,
                'src',
            );

            foreach (array_map(null, $graph->split($input, $group->count()), $group->all()) as [$pad, $part]) {
                $pads[] = $graph->chain($pad, $this->part($part['clip'], $part['from'], $part['to']), 'clip');
                $length = max($length, $part['to']);
            }
        }

        $pad = count($pads) > 1
            ? $graph->chain($pads, 'amix=inputs=' . count($pads) . ':normalize=0:duration=longest', 'stem')
            : $pads[0];

        $effects = $mix->mixEffects($channels);

        if ($channels === 1 && collect($effects)->contains(fn($effect) => $effect->stereo())) {
            $pad = $graph->chain($pad, 'pan=stereo|c0=c0|c1=c0', 'up');
            $channels = 2;
            $effects = $mix->mixEffects($channels);
        }

        foreach ($effects as $effect) {
            $pad = $effect->apply($graph, $pad);
        }

        return $graph->chain($pad, 'volume=' . round($mix->volume, 4) . ',' . $this->pan($mix->pan, $channels), 'track');
    }

    /**
     * Clips with a known length that fits the audio, fades scaled down when
     * they overlap, as in the editor.
     *
     * @param  list<array{start: float, offset: float, duration: ?float, gain: float, fadeIn: float, fadeOut: float}>  $clips
     * @return list<array{start: float, offset: float, duration: float, gain: float, fadeIn: float, fadeOut: float}>
     */
    protected function playable(array $clips, float $available): array
    {
        $out = [];

        foreach ($clips as $clip) {
            $duration = min($clip['duration'] ?? INF, $available - $clip['offset']);

            if ($duration <= 0.001 || ! is_finite($duration)) {
                continue;
            }

            $fades = $clip['fadeIn'] + $clip['fadeOut'];
            $ratio = $fades > $duration ? $duration / $fades : 1;

            $out[] = [
                ...$clip,
                'duration' => $duration,
                'fadeIn' => $clip['fadeIn'] * $ratio,
                'fadeOut' => $clip['fadeOut'] * $ratio,
            ];
        }

        return $out;
    }

    /**
     * Filters that cut a clip from its audio, apply its gain and linear fades,
     * keep the part heard between `from` and `to` on the timeline and move it
     * there. The fades follow the whole clip, so a covered end stays cut.
     *
     * @param  array{start: float, offset: float, duration: float, gain: float, fadeIn: float, fadeOut: float}  $clip
     */
    protected function part(array $clip, float $from, float $to): string
    {
        $filters = [
            sprintf('atrim=start=%s:duration=%s', round($clip['offset'], 6), round($clip['duration'], 6)),
            'asetpts=PTS-STARTPTS',
            'volume=' . round($clip['gain'], 4),
        ];

        if ($clip['fadeIn'] > 0) {
            $filters[] = sprintf('afade=t=in:st=0:d=%s:curve=tri', round($clip['fadeIn'], 6));
        }

        if ($clip['fadeOut'] > 0) {
            $filters[] = sprintf('afade=t=out:st=%s:d=%s:curve=tri', round($clip['duration'] - $clip['fadeOut'], 6), round($clip['fadeOut'], 6));
        }

        if ($from > $clip['start'] || $to < $clip['start'] + $clip['duration']) {
            $filters[] = sprintf('atrim=start=%s:end=%s', round($from - $clip['start'], 6), round($to - $clip['start'], 6));
            $filters[] = 'asetpts=PTS-STARTPTS';
        }

        $filters[] = 'adelay=delays=' . (int) round($from * MixGraph::RATE) . 'S:all=1';

        return implode(',', $filters);
    }

    /**
     * Equal power panning to stereo, as Web Audio's StereoPannerNode does it.
     */
    protected function pan(float $pan, int $channels): string
    {
        if ($channels === 1) {
            $x = ($pan + 1) / 2;

            return sprintf('pan=stereo|c0=%.6F*c0|c1=%.6F*c0', cos($x * M_PI / 2), sin($x * M_PI / 2));
        }

        if ($pan <= 0) {
            $x = $pan + 1;

            return sprintf('pan=stereo|c0=c0+%.6F*c1|c1=%.6F*c1', cos($x * M_PI / 2), sin($x * M_PI / 2));
        }

        return sprintf('pan=stereo|c0=%.6F*c0|c1=c1+%.6F*c0', cos($pan * M_PI / 2), sin($pan * M_PI / 2));
    }
}
