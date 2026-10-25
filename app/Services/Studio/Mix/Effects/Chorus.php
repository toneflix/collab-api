<?php

namespace App\Services\Studio\Mix\Effects;

use App\Services\Studio\Mix\MixGraph;

/**
 * A copy of the sound on a 20 ms delay that sweeps with an LFO, blended
 * with the dry sound.
 */
class Chorus extends MixEffect
{
    public const DEFAULTS = ['rate' => 0.8, 'depth' => 0.5, 'mix' => 0.5];

    public function apply(MixGraph $graph, string $in): string
    {
        $mix = $this->param('mix', 0, 1);
        $depth = max(0.01, 18 * $this->param('depth', 0, 1));

        return $graph->chain($in, sprintf(
            'chorus=%s:1:20:%s:%s:%s',
            self::number(1 - $mix),
            self::number(max(0.0001, $mix)),
            self::number(max(0.1, $this->param('rate', 0.05, 10))),
            self::number($depth),
        ), 'chor');
    }
}
