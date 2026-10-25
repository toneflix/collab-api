<?php

namespace App\Services\Studio\Mix\Effects;

use App\Services\Studio\Mix\MixGraph;

/**
 * Mid/side width: 0 is mono, 1 leaves the sound as it is, 2 doubles the side.
 */
class StereoWidth extends MixEffect
{
    public const DEFAULTS = ['width' => 1.5];

    public function stereo(): bool
    {
        return true;
    }

    public function apply(MixGraph $graph, string $in): string
    {
        $width = $this->param('width', 0, 2);
        $same = self::number((1 + $width) / 2);
        $other = self::number((1 - $width) / 2);

        return $graph->chain($in, "pan=stereo|c0={$same}*c0+{$other}*c1|c1={$other}*c0+{$same}*c1", 'wide');
    }
}
