<?php

namespace App\Services\Ai\Replicate;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Runs models on Replicate: starts a prediction, waits for it and fetches
 * its files.
 *
 * @phpstan-type Prediction array{id: string, status: string, output: mixed, error: ?string, metrics?: array<string, mixed>}
 */
class ReplicateClient
{
    protected const DONE = ['succeeded', 'failed', 'canceled'];

    /**
     * @param  array{key?: ?string, url?: ?string, timeout?: int}  $config
     */
    public function __construct(protected array $config) {}

    public static function make(): self
    {
        return new self((array) config('ai.providers.replicate', []));
    }

    /**
     * Run a model and wait for its output. A model is "owner/name", or
     * "owner/name:version" to pin a version.
     *
     * @param  array<string, mixed>  $input
     * @return Prediction
     */
    public function run(string $model, array $input, ?int $timeout = null): array
    {
        $timeout ??= (int) ($this->config['timeout'] ?? 600);
        $deadline = now()->addSeconds($timeout);

        $prediction = $this->create($model, $input);

        while (! in_array($prediction['status'], self::DONE, true)) {
            if (now()->greaterThan($deadline)) {
                $this->cancel($prediction['id']);

                throw new ReplicateException("{$model} took longer than {$timeout} seconds.");
            }

            Sleep::for(2)->seconds();
            $prediction = $this->prediction($prediction['id']);
        }

        if ($prediction['status'] !== 'succeeded') {
            throw new ReplicateException($prediction['error'] ?: "{$model} {$prediction['status']}.");
        }

        return $prediction;
    }

    /**
     * Start a prediction, waiting up to a minute for it to finish. Models run
     * by name where Replicate allows it, otherwise by their newest version.
     *
     * @param  array<string, mixed>  $input
     * @return Prediction
     */
    public function create(string $model, array $input): array
    {
        [$name, $version] = array_pad(explode(':', $model, 2), 2, null);

        $request = fn () => $this->http()->withHeaders(['Prefer' => 'wait=60'])->timeout(75);

        if (! $version) {
            $response = $request()->post("models/{$name}/predictions", ['input' => $input]);

            if (! in_array($response->status(), [404, 422], true) || ! ($version = $this->latestVersion($name))) {
                return $this->prediction(null, $response->throw()->json());
            }
        }

        return $this->prediction(null, $request()->post('predictions', ['version' => $version, 'input' => $input])->throw()->json());
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return Prediction
     */
    public function prediction(?string $id, ?array $body = null): array
    {
        $body ??= $this->http()->get("predictions/{$id}")->throw()->json();

        return [
            'id' => (string) ($body['id'] ?? $id),
            'status' => (string) ($body['status'] ?? 'starting'),
            'output' => $body['output'] ?? null,
            'error' => isset($body['error']) ? (string) $body['error'] : null,
            'metrics' => (array) ($body['metrics'] ?? []),
        ];
    }

    public function cancel(string $id): void
    {
        rescue(fn () => $this->http()->post("predictions/{$id}/cancel"), report: false);
    }

    /**
     * Upload a local file so a model can read it, and return its URL.
     */
    public function upload(string $path, string $mime = 'application/octet-stream'): string
    {
        $response = $this->http()
            ->timeout(300)
            ->attach('content', fopen($path, 'r'), basename($path), ['Content-Type' => $mime])
            ->post('files')
            ->throw();

        return (string) $response->json('urls.get');
    }

    /**
     * Fetch an output file to a local temporary path.
     */
    public function download(string $url): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ai-');

        file_put_contents($path, $this->http()->timeout(300)->get($url)->throw()->body());

        return $path;
    }

    /**
     * The newest version of a model.
     */
    protected function latestVersion(string $model): ?string
    {
        return Cache::remember("replicate::version::{$model}", 3600, fn () => $this->http()->get("models/{$model}")->json('latest_version.id'));
    }

    protected function http(): PendingRequest
    {
        if (blank($this->config['key'] ?? null)) {
            throw new ReplicateException('Set REPLICATE_API_TOKEN in .env to use the AI tools.');
        }

        return Http::baseUrl(rtrim((string) ($this->config['url'] ?? 'https://api.replicate.com/v1'), '/').'/')
            ->withToken((string) $this->config['key'])
            ->acceptJson()
            ->timeout(60);
    }
}
