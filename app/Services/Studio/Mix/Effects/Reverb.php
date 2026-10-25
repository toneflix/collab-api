<?php

namespace App\Services\Studio\Mix\Effects;

use App\Services\Studio\Mix\MixGraph;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

/**
 * A room made from a decaying noise burst, the same one the studio plays the
 * stem through, scaled the way Web Audio's ConvolverNode scales it.
 */
class Reverb extends MixEffect
{
    public const DEFAULTS = ['size' => 2.5, 'decay' => 3, 'wet' => 0.3];

    public function apply(MixGraph $graph, string $in): string
    {
        $wet = $this->param('wet', 0, 1);

        if ($wet <= 0) {
            return $in;
        }

        $room = $graph->input($this->impulse($graph->file('wav')));

        [$dry, $send] = $graph->split($in, 2);

        $tail = $graph->chain([$send, $graph->chain($room, 'aresample=' . MixGraph::RATE, 'ir')], 'afir=dry=1:wet=1:' . self::unscaled() . ',volume=' . self::number($wet), 'rev');
        $kept = $graph->chain($dry, 'volume=' . self::number(1 - $wet * 0.5), 'dry');

        return $graph->chain([$kept, $tail], 'amix=inputs=2:normalize=0:duration=first', 'rvb');
    }

    /**
     * Write the room's impulse response as a 32 bit float WAV.
     */
    protected function impulse(string $path): string
    {
        $size = $this->param('size', 0.3, 8);
        $decay = $this->param('decay', 1, 8);
        $length = max(1, (int) floor(MixGraph::RATE * $size));

        $samples = [];
        $power = 0.0;
        for ($c = 0; $c < $this->channels; $c++) {
            for ($i = 0; $i < $length; $i++) {
                $value = (mt_rand() / mt_getrandmax() * 2 - 1) * (1 - $i / $length) ** $decay;
                $samples[$c][$i] = $value;
                $power += $value * $value;
            }
        }

        // ConvolverNode's normalization, from the Web Audio spec.
        $scale = 0.00125 / max(0.000125, sqrt($power / ($this->channels * $length)));

        $data = '';
        for ($i = 0; $i < $length; $i++) {
            for ($c = 0; $c < $this->channels; $c++) {
                $data .= pack('g', $samples[$c][$i] * $scale);
            }
        }

        $bytes = strlen($data);
        $block = $this->channels * 4;

        file_put_contents($path, 'RIFF' . pack('V', 36 + $bytes) . 'WAVEfmt '
            . pack('VvvVVvv', 16, 3, $this->channels, MixGraph::RATE, MixGraph::RATE * $block, $block, 32)
            . 'data' . pack('V', $bytes) . $data);

        return $path;
    }

    /**
     * The afir option that leaves the impulse response as it is. Newer FFmpeg
     * renamed it.
     */
    protected static function unscaled(): string
    {
        return Cache::rememberForever('mix::afir-option', function () {
            $process = new Process([config('laravel-ffmpeg.ffmpeg.binaries') ?: 'ffmpeg', '-hide_banner', '-h', 'filter=afir']);
            $process->run();

            return str_contains($process->getOutput(), 'irnorm') ? 'irnorm=-1' : 'gtype=none';
        });
    }
}
