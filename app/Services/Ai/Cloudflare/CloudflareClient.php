<?php

namespace App\Services\Ai\Cloudflare;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Runs models on Cloudflare Workers AI over its REST API. Cloudflare's own
 * models (`@cf/…`) and partner models (like `elevenlabs/music-v2`) both
 * answer within the request, so nothing is polled.
 */
class CloudflareClient
{
    /**
     * @param  array{account?: ?string, key?: ?string, url?: ?string, timeout?: int}  $config
     */
    public function __construct(protected array $config) {}

    public static function make(): self
    {
        return new self((array) config('ai.providers.cloudflare', []));
    }

    /**
     * Run a model and return its result.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function run(string $model, array $input, ?int $timeout = null): array
    {
        $response = str_starts_with($model, '@cf/')
            ? $this
                ->http($timeout)
                ->post("ai/run/{$model}", $input)
            : $this
                ->http($timeout)
                ->post('ai/run', ['model' => $model, 'input' => $input]);

        $body = $this->body($response, $model);
        $state = $body['state'] ?? null;

        if ($state !== null && $state !== 'Completed') {
            throw new CloudflareException("{$model} ended {$state}.");
        }

        return (array) ($body['result'] ?? []);
    }

    /**
     * Ask a language model and return its text answer. Anthropic models
     * take the Messages API, the others the OpenAI-style chat API. An image
     * is a public URL the model looks at.
     */
    public function chat(string $model, string $system, string $prompt, int $maxTokens = 1024, ?string $image = null, ?int $timeout = null): string
    {
        return str_starts_with($model, 'anthropic/')
            ? $this->messages($model, $system, $prompt, $maxTokens, $image, $timeout)
            : $this->completions($model, $system, $prompt, $maxTokens, $image, $timeout);
    }

    /**
     * Fetch a file a model made to a local temporary path.
     */
    public function download(string $url): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cf-ai-');

        $content = Http::timeout(300)
            ->get($url)
            ->throw()
            ->body();

        file_put_contents($path, $content);

        return $path;
    }

    protected function messages(string $model, string $system, string $prompt, int $maxTokens, ?string $image, ?int $timeout): string
    {
        $content = $image
            ? [['type' => 'image', 'source' => ['type' => 'url', 'url' => $image]], ['type' => 'text', 'text' => $prompt]]
            : $prompt;

        $response = $this
            ->http($timeout)
            ->post('ai/v1/messages', [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $content]],
            ]);

        return collect((array) ($this->body($response, $model)['content'] ?? []))
            ->where('type', 'text')
            ->pluck('text')
            ->join('');
    }

    protected function completions(string $model, string $system, string $prompt, int $maxTokens, ?string $image, ?int $timeout): string
    {
        $content = $image
            ? [['type' => 'image_url', 'image_url' => ['url' => $image]], ['type' => 'text', 'text' => $prompt]]
            : $prompt;

        $response = $this
            ->http($timeout)
            ->post('ai/v1/chat/completions', [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $content],
                ],
            ]);

        return (string) data_get($this->body($response, $model), 'choices.0.message.content', '');
    }

    /**
     * The response body, or an error with Cloudflare's own reason.
     *
     * @return array<string, mixed>
     */
    protected function body(Response $response, string $model): array
    {
        $body = (array) $response->json();

        if ($response->failed() || ($body['success'] ?? true) === false) {
            $reason = data_get($body, 'errors.0.message')
                ?? data_get($body, 'error.message')
                ?? (is_string($body['error'] ?? null) ? $body['error'] : null)
                ?? mb_substr($response->body(), 0, 500);

            throw new CloudflareException("{$model} answered {$response->status()}: {$reason}");
        }

        return $body;
    }

    protected function http(?int $timeout = null): PendingRequest
    {
        if (blank($this->config['key'] ?? null) || blank($this->config['account'] ?? null)) {
            throw new CloudflareException('Set CLOUDFLARE_ACCOUNT_ID and CLOUDFLARE_API_TOKEN in .env to use Workers AI.');
        }

        $base = rtrim((string) ($this->config['url'] ?? 'https://api.cloudflare.com/client/v4'), '/');

        return Http::baseUrl("{$base}/accounts/{$this->config['account']}/")
            ->withToken((string) $this->config['key'])
            ->acceptJson()
            ->timeout($timeout ?? (int) ($this->config['timeout'] ?? 600));
    }
}
