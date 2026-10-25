<?php

namespace App\Services\Studio\Mix\Effects;

use App\Services\Studio\Mix\MixGraph;

/**
 * A swirling phase sweep, blended with the dry sound.
 */
class Phaser extends MixEffect
{
    public const DEFAULTS = ['rate' => 0.5, 'depth' => 0.6, 'feedback' => 0.4, 'mix' => 0.5];

    public function apply(MixGraph $graph, string $in): string
    {
        $mix = $this->param('mix', 0, 1);

        if ($mix <= 0) {
            return $in;
        }

        [$dry, $send] = $graph->split($in, 2);

        $wet = $graph->chain($send, sprintf(
            'aphaser=in_gain=1:out_gain=%s:delay=%s:decay=%s:speed=%s:type=s,volume=%s',
            self::number(0.6 + 0.3 * $this->param('depth', 0, 1)),
            self::number(1 + 3 * $this->param('depth', 0, 1)),
            self::number(min(0.99, $this->param('feedback', 0, 0.9))),
            self::number(min(2, max(0.1, $this->param('rate', 0.05, 10)))),
            self::number($mix),
        ), 'phs');
        $kept = $graph->chain($dry, 'volume=' . self::number(1 - $mix), 'dry');

        return $graph->chain([$kept, $wet], 'amix=inputs=2:normalize=0:duration=first', 'phm');
    }
}
