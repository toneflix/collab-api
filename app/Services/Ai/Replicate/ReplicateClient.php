<?php

namespace App\Services\Ai\Replicate;

use App\Services\Ai\AiJobStopped;
use App\Services\Ai\AiJobStops;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

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
     * Times a throttled prediction is tried again.
     */
    protected const THROTTLE_RETRIES = 3;

    /**
     * Times a request is tried again after Replicate answers 5xx.
     */
    protected const SERVER_RETRIES = 3;

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
        $deadline = now()
            ->addSeconds($timeout);

        $prediction = $this->create($model, $input);

        while (! in_array($prediction['status'], self::DONE, true)) {
            try {
                app(AiJobStops::class)->check();
            } catch (AiJobStopped $e) {
                $this->cancel($prediction['id']);

                throw $e;
            }

            if (now()->greaterThan($deadline)) {
                $this->cancel($prediction['id']);

                $stage = $prediction['status'] === 'starting' ? 'waiting to start' : 'still running';

                throw new ReplicateException("{$model} took longer than {$timeout} seconds ({$stage}).");
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
        $version ??= Cache::get($this->versionKey($name));

        if (! $version) {
            $response = $this->start("models/{$name}/predictions", ['input' => $input]);

            if (! in_array($response->status(), [404, 422], true) || ! ($version = $this->latestVersion($name))) {
                return $this->prediction(null, $response->throw()->json());
            }
        }

        return $this->prediction(null, $this->start('predictions', ['version' => $version, 'input' => $input])->throw()->json());
    }

    /**
     * Post a new prediction, waiting out Replicate's rate limit and brief
     * outages (5xx) a few times before giving up.
     *
     * @param  array<string, mixed>  $body
     */
    protected function start(string $path, array $body): Response
    {
        $throttled = 0;
        $failed = 0;

        while (true) {
            $response = $this
                ->http()
                ->withHeaders(['Prefer' => 'wait=60'])
                ->timeout(75)
                ->post($path, $body);

            if ($response->status() === 429 && ++$throttled <= self::THROTTLE_RETRIES) {
                $wait = (int) ($response->json('retry_after') ?? $response->header('Retry-After') ?: 10);

                Sleep::for(min(60, max(1, $wait)))->seconds();

                continue;
            }

            if ($response->serverError() && ++$failed <= self::SERVER_RETRIES) {
                Sleep::for(5 * $failed)->seconds();

                continue;
            }

            return $response;
        }
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return Prediction
     */
    public function prediction(?string $id, ?array $body = null): array
    {
        $body ??= $this
            ->http()
            ->retry(
                self::SERVER_RETRIES,
                fn(int $attempt) => 5000 * $attempt,
                fn(Throwable $e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()),
            )
            ->get("predictions/{$id}")
            ->throw()
            ->json();

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
        rescue(fn() => $this->http()->post("predictions/{$id}/cancel"), report: false);
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
     * Fetch an output file to a local temporary path. Outputs are public URLs
     * or data URIs; only files on the API itself need the token.
     */
    public function download(string $url): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ai-');

        if (str_starts_with($url, 'data:')) {
            $content = base64_decode(Str::after($url, 'base64,'), true);

            if ($content === false || ! str_contains($url, ';base64,')) {
                throw new ReplicateException('Replicate returned an unreadable file.');
            }
        } else {
            $request = parse_url($url, PHP_URL_HOST) === parse_url($this->baseUrl(), PHP_URL_HOST)
                ? $this->http()
                : Http::timeout(60);

            $content = $request
                ->timeout(300)
                ->get($url)
                ->throw()
                ->body();
        }

        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Whether a model output value is a file we can fetch.
     */
    public static function isFile(mixed $value): bool
    {
        return is_string($value) && (str_starts_with($value, 'https://') || str_starts_with($value, 'http://') || str_starts_with($value, 'data:'));
    }

    /**
     * The newest version of a model.
     */
    protected function latestVersion(string $model): ?string
    {
        return Cache::remember($this->versionKey($model), 3600, fn() => $this->http()->get("models/{$model}")->json('latest_version.id'));
    }

    protected function versionKey(string $model): string
    {
        return "replicate::version::{$model}";
    }

    protected function baseUrl(): string
    {
        return rtrim((string) ($this->config['url'] ?? 'https://api.replicate.com/v1'), '/') . '/';
    }

    protected function http(): PendingRequest
    {
        if (blank($this->config['key'] ?? null)) {
            throw new ReplicateException('Set REPLICATE_API_TOKEN in .env to use the AI tools.');
        }

        return Http::baseUrl($this->baseUrl())
            ->withToken((string) $this->config['key'])
            ->acceptJson()
            ->timeout(60);
    }
}
