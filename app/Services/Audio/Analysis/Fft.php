<?php

namespace App\Services\Audio\Analysis;

/**
 * An in-place radix-2 FFT for one frame size, with its twiddles and
 * bit-reversal order worked out once.
 */
class Fft
{
    /** @var list<float> */
    protected array $cos = [];

    /** @var list<float> */
    protected array $sin = [];

    /** @var list<int> */
    protected array $reverse = [];

    /** @var list<float> */
    protected array $window = [];

    public function __construct(public readonly int $size)
    {
        $bits = (int) log($size, 2);

        for ($i = 0; $i < $size / 2; $i++) {
            $this->cos[$i] = cos(2 * M_PI * $i / $size);
            $this->sin[$i] = -sin(2 * M_PI * $i / $size);
        }

        for ($i = 0; $i < $size; $i++) {
            $this->reverse[$i] = bindec(strrev(str_pad(decbin($i), $bits, '0', STR_PAD_LEFT)));
            $this->window[$i] = 0.5 - 0.5 * cos(2 * M_PI * $i / ($size - 1));
        }
    }

    /**
     * Magnitudes of the first half of a Hann windowed frame's spectrum.
     *
     * @param  array<int, float>  $samples
     * @return list<float>
     */
    public function magnitudes(array $samples, int $offset): array
    {
        $n = $this->size;
        $re = [];
        $im = array_fill(0, $n, 0.0);

        foreach ($this->reverse as $i => $j) {
            $re[$j] = ($samples[$offset + $i] ?? 0.0) * $this->window[$i];
        }
        ksort($re);

        for ($len = 2; $len <= $n; $len <<= 1) {
            $half = $len >> 1;
            $step = intdiv($n, $len);

            for ($start = 0; $start < $n; $start += $len) {
                for ($k = 0; $k < $half; $k++) {
                    $wr = $this->cos[$k * $step];
                    $wi = $this->sin[$k * $step];
                    $a = $start + $k;
                    $b = $a + $half;
                    $tr = $wr * $re[$b] - $wi * $im[$b];
                    $ti = $wr * $im[$b] + $wi * $re[$b];
                    $re[$b] = $re[$a] - $tr;
                    $im[$b] = $im[$a] - $ti;
                    $re[$a] += $tr;
                    $im[$a] += $ti;
                }
            }
        }

        $magnitudes = [];
        for ($i = 0; $i <= $n / 2; $i++) {
            $magnitudes[$i] = sqrt($re[$i] * $re[$i] + $im[$i] * $im[$i]);
        }

        return $magnitudes;
    }
}
