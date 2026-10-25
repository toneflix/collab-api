<?php

namespace App\Services\Studio\Mix\Effects;

use App\Services\Studio\Mix\MixGraph;

/**
 * A two pole high-pass filter. Its resonance is in dB, as Web Audio reads it.
 */
class HighPass extends MixEffect
{
    public const DEFAULTS = ['frequency' => 120, 'q' => 0];

    public function apply(MixGraph $graph, string $in): string
    {
        $frequency = $this->param('frequency', 20, 5000);
        $q = 10 ** ($this->param('q', 0, 20) / 20);

        return $graph->chain($in, 'highpass=p=2:t=q:f=' . self::number($frequency) . ':w=' . self::number($q), 'hp');
    }
}
