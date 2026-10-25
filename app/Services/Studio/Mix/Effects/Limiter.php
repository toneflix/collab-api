<?php

namespace App\Services\Studio\Mix\Effects;

use App\Services\Studio\Mix\MixGraph;

/**
 * Keeps peaks under the ceiling, without raising the level afterwards.
 */
class Limiter extends MixEffect
{
    public const DEFAULTS = ['ceiling' => -1, 'release' => 0.1];

    public function apply(MixGraph $graph, string $in): string
    {
        $limit = max(0.0625, 10 ** ($this->param('ceiling', -24, 0) / 20));
        $release = $this->param('release', 0.01, 1) * 1000;

        return $graph->chain($in, 'alimiter=limit=' . self::number($limit) . ':attack=1:release=' . self::number($release) . ':level=0', 'lim');
    }
}
