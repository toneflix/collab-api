<?php

namespace App\Services\Studio\Mix\Effects;

use App\Services\Studio\Mix\MixGraph;

/**
 * Volume that rises and falls: 1 - depth/2 + depth/2 · sin(2π · rate · t).
 */
class Tremolo extends MixEffect
{
    public const DEFAULTS = ['rate' => 5, 'depth' => 0.5];

    public function apply(MixGraph $graph, string $in): string
    {
        $rate = self::number($this->param('rate', 0.1, 20));
        $half = self::number($this->param('depth', 0, 1) / 2);

        return $graph->chain($in, $this->perSample(
            fn(string $x) => "{$x}*(1-{$half}+{$half}*sin(2*PI*{$rate}*t))",
        ), 'trem');
    }
}
