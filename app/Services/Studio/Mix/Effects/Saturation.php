<?php

namespace App\Services\Studio\Mix\Effects;

use App\Services\Studio\Mix\MixGraph;

/**
 * Soft clipping, tanh(drive · x), blended with the dry sound.
 */
class Saturation extends MixEffect
{
    public const DEFAULTS = ['drive' => 12, 'mix' => 1];

    public function apply(MixGraph $graph, string $in): string
    {
        $drive = self::number(10 ** ($this->param('drive', 0, 30) / 20));
        $mix = $this->param('mix', 0, 1);

        return $graph->chain($in, $this->perSample(
            fn(string $x) => self::number(1 - $mix) . "*{$x}+" . self::number($mix) . "*tanh({$drive}*{$x})",
        ), 'sat');
    }
}
