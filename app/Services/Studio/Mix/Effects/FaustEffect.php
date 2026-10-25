<?php

namespace App\Services\Studio\Mix\Effects;

use App\Services\Studio\Faust\FaustLibrary;
use App\Services\Studio\Mix\MixGraph;
use Illuminate\Support\Facades\Log;

/**
 * A Faust effect, run through the LADSPA plugin built from the same source
 * the studio compiles in the browser.
 */
class FaustEffect extends MixEffect
{
    /**
     * @param  array<string, float>  $params
     */
    public function __construct(
        protected string $type,
        array $params,
        int $channels = 2,
    ) {
        parent::__construct($params, $channels);
    }

    public function stereo(): bool
    {
        return true;
    }

    public function apply(MixGraph $graph, string $in): string
    {
        $library = app(FaustLibrary::class);
        $effect = $library->find($this->type);
        $plugin = $effect ? $library->plugin($this->type) : null;

        if (! $plugin) {
            Log::warning('Faust effect left out of the mix', ['effect' => $this->type]);

            return $in;
        }

        $controls = collect($effect['params'])
            ->map(fn(array $p, int $i) => "c{$i}=" . self::number(max($p['min'], min($p['max'], $this->params[$p['key']] ?? $p['init']))))
            ->implode('|');

        return $graph->chain($in, sprintf(
            "ladspa=file='%s':plugin=%s%s",
            $plugin['file'],
            $plugin['label'],
            $controls !== '' ? ':controls=' . $controls : '',
        ), 'faust');
    }
}
