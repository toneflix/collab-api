<?php

namespace App\Services\Studio\Mix;

/**
 * An FFmpeg filter graph under construction: its input files, its filter
 * chains and the temporary files it needs.
 */
class MixGraph
{
    /** Sample rate everything is mixed at */
    public const RATE = 44100;

    /** @var list<string> */
    protected array $inputs = [];

    /** @var list<string> */
    protected array $chains = [];

    /** @var list<string> */
    protected array $temp = [];

    protected int $labels = 0;

    /**
     * Add an input file and return its stream label, like "[0:a]".
     */
    public function input(string $path): string
    {
        $this->inputs[] = $path;

        return '['.(count($this->inputs) - 1).':a]';
    }

    /**
     * A new, unused pad label.
     */
    public function label(string $prefix = 'x'): string
    {
        return '['.$prefix.$this->labels++.']';
    }

    /**
     * Add a chain of filters from the given pads to a new pad, and return it.
     *
     * @param  string|list<string>  $from
     */
    public function chain(string|array $from, string $filters, string $prefix = 'x'): string
    {
        $out = $this->label($prefix);
        $this->chains[] = implode('', (array) $from).$filters.$out;

        return $out;
    }

    /**
     * Copy a pad into several, and return them.
     *
     * @return list<string>
     */
    public function split(string $from, int $count): array
    {
        if ($count < 2) {
            return [$from];
        }

        $outs = array_map(fn () => $this->label('split'), range(1, $count));
        $this->chains[] = "{$from}asplit={$count}".implode('', $outs);

        return $outs;
    }

    /**
     * A path for a temporary file that's removed when the graph is cleaned up.
     */
    public function file(string $ext): string
    {
        $base = tempnam(sys_get_temp_dir(), 'mix-');
        @unlink($base);

        return $this->temp[] = "{$base}.{$ext}";
    }

    /**
     * FFmpeg arguments that run the graph, ending at the pad given.
     *
     * @return list<string>
     */
    public function arguments(string $out): array
    {
        $args = [];
        foreach ($this->inputs as $input) {
            $args = [...$args, '-i', $input];
        }

        return [...$args, '-filter_complex', implode(';', $this->chains), '-map', $out];
    }

    /**
     * Remove a file along with the graph's own temporary files.
     */
    public function own(string $path): string
    {
        return $this->temp[] = $path;
    }

    public function cleanup(): void
    {
        array_map(fn (string $file) => @unlink($file), $this->temp);
        $this->temp = [];
    }
}
