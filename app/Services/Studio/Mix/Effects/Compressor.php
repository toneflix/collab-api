<?php

namespace App\Services\Studio\Mix\Effects;

use App\Services\Studio\Mix\MixGraph;

/**
 * A compressor that reacts to peaks and adds back the level it takes away,
 * like Web Audio's DynamicsCompressor.
 */
class Compressor extends MixEffect
{
    public const DEFAULTS = ['threshold' => -24, 'knee' => 30, 'ratio' => 12, 'attack' => 0.003, 'release' => 0.25];

    public function apply(MixGraph $graph, string $in): string
    {
        $threshold = $this->param('threshold', -60, 0);
        $ratio = $this->param('ratio', 1, 20);

        // Web Audio raises the output by 0.6 of the gain lost at full scale.
        $makeup = 10 ** (-0.6 * $threshold * (1 - 1 / $ratio) / 20);

        return $graph->chain($in, implode(':', [
            'acompressor=detection=peak',
            'threshold='.self::number(max(0.000976563, 10 ** ($threshold / 20))),
            'ratio='.self::number($ratio),
            'knee='.self::number(max(1, min(8, $this->param('knee', 0, 40)))),
            'attack='.self::number(max(0.01, $this->param('attack', 0, 1) * 1000)),
            'release='.self::number(max(0.01, $this->param('release', 0, 1) * 1000)),
            'makeup='.self::number(max(1, min(64, $makeup))),
        ]), 'comp');
    }
}
