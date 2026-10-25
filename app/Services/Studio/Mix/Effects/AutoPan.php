<?php

namespace App\Services\Studio\Mix\Effects;

use App\Services\Studio\Mix\MixGraph;

/**
 * The sound swings between left and right, panned the way Web Audio's
 * StereoPannerNode pans a stereo sound.
 */
class AutoPan extends MixEffect
{
    public const DEFAULTS = ['rate' => 0.5, 'depth' => 0.8];

    public function stereo(): bool
    {
        return true;
    }

    public function apply(MixGraph $graph, string $in): string
    {
        $pan = self::number($this->param('depth', 0, 1)) . '*sin(2*PI*' . self::number($this->param('rate', 0.05, 10)) . '*t)';

        $left = "if(lt({$pan},0),val(0)+val(1)*cos(({$pan}+1)*PI/2),val(0)*cos({$pan}*PI/2))";
        $right = "if(lt({$pan},0),val(1)*sin(({$pan}+1)*PI/2),val(1)+val(0)*sin({$pan}*PI/2))";

        return $graph->chain($in, "aeval=exprs='{$left}|{$right}':c=same", 'apan');
    }
}
