<?php

namespace App\Services\Studio\Mix\Effects;

use App\Services\Studio\Mix\MixGraph;

/**
 * A two pole low-pass filter. Its resonance is in dB, as Web Audio reads it.
 */
class LowPass extends MixEffect
{
    public const DEFAULTS = ['frequency' => 1000, 'q' => 1];

    public function apply(MixGraph $graph, string $in): string
    {
        $frequency = $this->param('frequency', 20, MixGraph::RATE / 2 - 50);
        $q = 10 ** ($this->param('q', 0.1, 20) / 20);

        return $graph->chain($in, 'lowpass=p=2:t=q:f='.self::number($frequency).':w='.self::number($q), 'lp');
    }
}
