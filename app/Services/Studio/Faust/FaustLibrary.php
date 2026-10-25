<?php

namespace App\Services\Studio\Faust;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The studio's Faust effects: their sources, their settings read from the
 * Faust compiler, and the LADSPA plugins FFmpeg runs them with.
 */
class FaustLibrary
{
    /** Effect types are this prefix and the source's file name */
    public const PREFIX = 'faust-';

    public function __construct(
        protected ?string $directory = null,
    ) {
        $this->directory ??= resource_path('faust');
    }

    /**
     * Whether the Faust compiler is installed.
     */
    public function available(): bool
    {
        return Cache::remember('faust::available', 3600, fn() => (bool) (new ExecutableFinder)->find(config('studio.faust.binary')));
    }

    /**
     * Every effect, in name order, or none when the compiler is missing.
     *
     * @return list<array{type: string, name: string, label: string, group: string, description: string, source: string, params: list<array{key: string, address: string, min: float, max: float, step: float, init: float, unit: ?string}>}>
     */
    public function all(): array
    {
        if (! $this->available()) {
            return [];
        }

        return collect(File::glob($this->directory . '/*.dsp'))
            ->sort()
            ->map(fn(string $path) => $this->describe($path))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * One effect by its type, like "faust-hall".
     *
     * @return array{type: string, name: string, label: string, group: string, description: string, source: string, params: list<array{key: string, address: string, min: float, max: float, step: float, init: float, unit: ?string}>}|null
     */
    public function find(string $type): ?array
    {
        if (! str_starts_with($type, self::PREFIX) || ! $this->available()) {
            return null;
        }

        $slug = Str::after($type, self::PREFIX);
        $path = $this->directory . '/' . $slug . '.dsp';

        return preg_match('/^[a-z0-9_]+$/', $slug) && is_file($path) ? $this->describe($path) : null;
    }

    /**
     * The LADSPA plugin for an effect, built the first time it's needed.
     *
     * @return array{file: string, label: string}|null
     */
    public function plugin(string $type): ?array
    {
        $effect = $this->find($type);

        if (! $effect) {
            return null;
        }

        $slug = Str::after($type, self::PREFIX);
        $file = rtrim(config('studio.faust.plugins'), '/') . '/' . $slug . '-' . md5($effect['source']) . '.so';

        if (is_file($file)) {
            return ['file' => $file, 'label' => $slug];
        }

        return Cache::lock('faust::build:' . basename($file), 120)->block(120, function () use ($effect, $slug, $file) {
            if (! is_file($file) && ! $this->build($effect['source'], $slug, $file)) {
                return null;
            }

            return ['file' => $file, 'label' => $slug];
        });
    }

    /**
     * Compile a source into a LADSPA plugin at `$file`.
     */
    protected function build(string $source, string $slug, string $file): bool
    {
        $work = $this->workspace();

        try {
            File::put("{$work}/{$slug}.dsp", $source);

            $process = new Process([config('studio.faust.ladspa'), "{$slug}.dsp"], $work, timeout: 120);
            $process->run();

            if (! is_file("{$work}/{$slug}.so")) {
                Log::error('Faust effect build failed', ['effect' => $slug, 'output' => Str::limit($process->getErrorOutput() . $process->getOutput(), 2000)]);

                return false;
            }

            File::ensureDirectoryExists(dirname($file));
            File::move("{$work}/{$slug}.so", $file);

            return true;
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * An effect's name, menu details and settings, read from the compiler.
     *
     * @return array{type: string, name: string, label: string, group: string, description: string, source: string, params: list<array{key: string, address: string, min: float, max: float, step: float, init: float, unit: ?string}>}|null
     */
    protected function describe(string $path): ?array
    {
        $source = File::get($path);
        $slug = pathinfo($path, PATHINFO_FILENAME);

        return Cache::rememberForever('faust::describe:' . md5($slug . $source), function () use ($source, $slug) {
            $json = $this->json($source, $slug);

            if (! $json) {
                return null;
            }

            $meta = collect($json['meta'] ?? [])->collapse();

            return [
                'type' => self::PREFIX . $slug,
                'name' => (string) ($json['name'] ?? $slug),
                'label' => (string) $meta->get('tf_label', Str::headline($slug)),
                'group' => (string) $meta->get('tf_group', 'Colour'),
                'description' => (string) $meta->get('tf_description', ''),
                'source' => $source,
                'params' => $this->controls($json['ui'] ?? []),
            ];
        });
    }

    /**
     * The sliders of a Faust UI, in the order the plugin's control ports are.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array{key: string, address: string, min: float, max: float, step: float, init: float, unit: ?string}>
     */
    protected function controls(array $items): array
    {
        $out = [];

        foreach ($items as $item) {
            if (isset($item['items'])) {
                array_push($out, ...$this->controls($item['items']));

                continue;
            }

            if (in_array($item['type'] ?? '', ['hslider', 'vslider', 'nentry', 'checkbox', 'button'], true)) {
                $out[] = [
                    'key' => (string) ($item['shortname'] ?? $item['label']),
                    'address' => (string) $item['address'],
                    'min' => (float) ($item['min'] ?? 0),
                    'max' => (float) ($item['max'] ?? 1),
                    'step' => (float) ($item['step'] ?? 1),
                    'init' => (float) ($item['init'] ?? 0),
                    'unit' => collect($item['meta'] ?? [])->pluck('unit')->filter()->first(),
                ];
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function json(string $source, string $slug): ?array
    {
        $work = $this->workspace();

        try {
            File::put("{$work}/{$slug}.dsp", $source);

            $process = new Process([config('studio.faust.binary'), '-json', '-o', '/dev/null', "{$slug}.dsp"], $work, timeout: 60);
            $process->run();

            $json = "{$work}/{$slug}.dsp.json";

            if (! $process->isSuccessful() || ! is_file($json)) {
                Log::error('Faust effect could not be read', ['effect' => $slug, 'output' => Str::limit($process->getErrorOutput(), 2000)]);

                return null;
            }

            return json_decode(File::get($json), true);
        } finally {
            File::deleteDirectory($work);
        }
    }

    protected function workspace(): string
    {
        $work = sys_get_temp_dir() . '/faust-' . Str::random(12);
        File::ensureDirectoryExists($work);

        return $work;
    }
}
