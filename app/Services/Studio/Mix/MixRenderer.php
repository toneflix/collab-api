<?php

namespace App\Services\Studio\Mix;

use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use App\Services\Audio\StemAudio;
use App\Services\Studio\StemFiles;

/**
 * Mixes a project track's stems with FFmpeg, the way they sound in the
 * studio: clips, clip gain and fades, effects, volume, pan, mute, solo and
 * the master volume. The mix is turned down to just under full scale when
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
        $stems = $track->stems()->get()->map(fn (ProjectTrackStem $stem) => [$stem, MixStem::fromMeta(collect($stem->meta)->all())]);
        $soloed = $stems->contains(fn (array $item) => $item[1]->soloed);
        $audible = $stems->filter(fn (array $item) => $soloed ? $item[1]->soloed : ! $item[1]->muted);

        $graph = new MixGraph;
        $pads = [];
        $length = 0.0;

        try {
            foreach ($audible as [$stem, $mix]) {
                $path = $this->files->local($stem);

                if (! $path) {
                    continue;
                }

                $graph->own($path);
                $pad = $this->stem($graph, $path, $mix, $length);

                if ($pad) {
                    $pads[] = $pad;
                }
            }

            if (! $pads || $length <= 0) {
                return null;
            }

            $master = count($pads) > 1
                ? $graph->chain($pads, 'amix=inputs='.count($pads).':normalize=0:duration=longest', 'mix')
                : $pads[0];
            $master = $graph->chain($master, sprintf('volume=%s,atrim=end=%s', round(self::masterVolume($track), 4), round($length, 4)), 'out');

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
     * Add one stem to the graph and return its pad, or null when none of its
     * clips play. Stretches the mix length to the stem's last clip.
     */
    protected function stem(MixGraph $graph, string $path, MixStem $mix, float &$length): ?string
    {
        $info = $this->audio->probe($path);
        $channels = $info['channels'];
        $clips = $this->playable($mix->clips, $info['duration']);

        if (! $clips) {
            return null;
        }

        $source = $graph->chain(
            $graph->input($path),
            'aresample='.MixGraph::RATE.',aformat=sample_fmts=fltp:channel_layouts='.($channels === 1 ? 'mono' : 'stereo'),
            'src',
        );

        $pads = [];
        foreach (array_map(null, $graph->split($source, count($clips)), $clips) as [$pad, $clip]) {
            $pads[] = $graph->chain($pad, $this->clip($clip), 'clip');
            $length = max($length, $clip['start'] + $clip['duration']);
        }

        $pad = count($pads) > 1
            ? $graph->chain($pads, 'amix=inputs='.count($pads).':normalize=0:duration=longest', 'stem')
            : $pads[0];

        foreach ($mix->mixEffects($channels) as $effect) {
            $pad = $effect->apply($graph, $pad);
        }

        return $graph->chain($pad, 'volume='.round($mix->volume, 4).','.$this->pan($mix->pan, $channels), 'track');
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
     * Filters that cut a clip from the stem, apply its gain and linear fades,
     * and move it to its place on the timeline.
     *
     * @param  array{start: float, offset: float, duration: float, gain: float, fadeIn: float, fadeOut: float}  $clip
     */
    protected function clip(array $clip): string
    {
        $filters = [
            sprintf('atrim=start=%s:duration=%s', round($clip['offset'], 6), round($clip['duration'], 6)),
            'asetpts=PTS-STARTPTS',
            'volume='.round($clip['gain'], 4),
        ];

        if ($clip['fadeIn'] > 0) {
            $filters[] = sprintf('afade=t=in:st=0:d=%s:curve=tri', round($clip['fadeIn'], 6));
        }

        if ($clip['fadeOut'] > 0) {
            $filters[] = sprintf('afade=t=out:st=%s:d=%s:curve=tri', round($clip['duration'] - $clip['fadeOut'], 6), round($clip['fadeOut'], 6));
        }

        $filters[] = 'adelay=delays='.(int) round($clip['start'] * MixGraph::RATE).'S:all=1';

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
