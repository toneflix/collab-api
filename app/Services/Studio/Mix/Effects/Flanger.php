<?php

namespace App\Services\Studio\Mix\Effects;

use App\Services\Studio\Mix\MixGraph;

/**
 * A short delay sweeping around 4 ms with feedback, for a jet-like whoosh.
 */
class Flanger extends MixEffect
{
    public const DEFAULTS = ['rate' => 0.25, 'depth' => 0.7, 'feedback' => 0.5, 'mix' => 0.5];

    public function apply(MixGraph $graph, string $in): string
    {
        $depth = $this->param('depth', 0, 1);

        return $graph->chain($in, sprintf(
            'flanger=delay=%s:depth=%s:regen=%s:width=%s:speed=%s',
            self::number(max(0, 4 - 3.6 * $depth)),
            self::number(min(10, 7.2 * $depth)),
            self::number(min(95, $this->param('feedback', 0, 0.9) * 100)),
            self::number($this->param('mix', 0, 1) * 100),
            self::number(max(0.1, $this->param('rate', 0.05, 10))),
        ), 'flng');
    }
}
