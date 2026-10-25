<?php

namespace App\Services\Studio\Mix\Effects;

use App\Services\Studio\Mix\MixGraph;

/**
 * Echoes that repeat every delay time, each one quieter by the feedback,
 * mixed with the dry sound.
 */
class Delay extends MixEffect
{
    public const DEFAULTS = ['delayTime' => 0.25, 'feedback' => 0.4, 'wet' => 0.35];

    /** Most echoes rendered, after which they are too quiet to hear */
    protected const MAX_ECHOES = 32;

    /** Echoes quieter than this are left out */
    protected const FLOOR = 0.001;

    public function apply(MixGraph $graph, string $in): string
    {
        $time = $this->param('delayTime', 0.01, 10);
        $feedback = $this->param('feedback', 0, 0.95);
        $wet = $this->param('wet', 0, 1);

        if ($wet <= 0) {
            return $in;
        }

        $delays = [];
        $decays = [];
        for ($echo = 1, $level = $wet; $echo <= self::MAX_ECHOES && $level >= self::FLOOR; $echo++, $level *= $feedback) {
            $delays[] = self::number($time * $echo * 1000);
            $decays[] = self::number($level);
        }

        return $graph->chain($in, sprintf(
            'aecho=in_gain=%s:out_gain=1:delays=%s:decays=%s',
            self::number(1 - $wet),
            implode('|', $delays),
            implode('|', $decays),
        ), 'dly');
    }
}
