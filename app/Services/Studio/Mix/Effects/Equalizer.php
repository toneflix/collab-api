<?php

namespace App\Services\Studio\Mix\Effects;

use App\Services\Studio\Mix\MixGraph;

/**
 * The studio's three band EQ: a low shelf at 250 Hz, a peak at the mid
 * frequency and a high shelf at 4 kHz.
 */
class Equalizer extends MixEffect
{
    public const DEFAULTS = ['low' => 0, 'mid' => 0, 'midFrequency' => 1000, 'high' => 0];

    public function apply(MixGraph $graph, string $in): string
    {
        $low = $this->param('low', -18, 18);
        $mid = $this->param('mid', -18, 18);
        $high = $this->param('high', -18, 18);

        $filters = array_filter([
            $low != 0 ? 'lowshelf=f=250:t=s:w=1:g=' . self::number($low) : null,
            $mid != 0 ? 'equalizer=f=' . self::number($this->param('midFrequency', 20, 20000)) . ':t=q:w=0.9:g=' . self::number($mid) : null,
            $high != 0 ? 'highshelf=f=4000:t=s:w=1:g=' . self::number($high) : null,
        ]);

        return $filters ? $graph->chain($in, implode(',', $filters), 'eq') : $in;
    }
}
