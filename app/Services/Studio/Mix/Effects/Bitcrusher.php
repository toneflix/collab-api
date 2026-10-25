<?php

namespace App\Services\Studio\Mix\Effects;

use App\Services\Studio\Mix\MixGraph;

/**
 * Each sample rounded to 2^(bits-1) steps, blended with the dry sound.
 */
class Bitcrusher extends MixEffect
{
    public const DEFAULTS = ['bits' => 6, 'mix' => 1];

    public function apply(MixGraph $graph, string $in): string
    {
        $steps = self::number(2 ** (round($this->param('bits', 1, 16)) - 1));
        $mix = $this->param('mix', 0, 1);

        return $graph->chain($in, $this->perSample(
            fn(string $x) => self::number(1 - $mix) . "*{$x}+" . self::number($mix) . "*round({$x}*{$steps})/{$steps}",
        ), 'crush');
    }
}
