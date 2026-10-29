<?php

namespace App\Services\Ai\Suno;

use App\Services\Ai\AiJobStops;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Makes songs and splits stems with Suno through sunoapi.org. Every task is
 * started, then polled until it's done; the callback Suno requires only
 * points at a route that ignores it.
 *
 * @phpstan-type SunoSong array{task: string, id: string, url: string, title: ?string, duration: ?float, image: ?string, tags: ?string}
 */
class SunoClient
{
    /** Seconds between two looks at a running task */
    protected const POLL_SECONDS = 5;

    /** Times a throttled request is tried again */
    protected const THROTTLE_RETRIES = 3;

    /** Codes Suno answers with when called too often */
    protected const THROTTLED = [405, 430];

    protected const FAILED = ['CREATE_TASK_FAILED', 'GENERATE_AUDIO_FAILED', 'SENSITIVE_WORD_ERROR'];

    /**
     * @param  array{key?: ?string, url?: ?string, upload_url?: ?string, timeout?: int}  $config
     */
    public function __construct(protected array $config) {}

    public static function make(): self
    {
        return new self((array) config('ai.providers.suno', []));
    }

    /**
     * Make a song and wait for its versions, usually two.
     *
     * @param  array<string, mixed>  $body
     * @return list<SunoSong>
     */
    public function generate(array $body): array
    {
        return $this->songs('api/v1/generate', $body);
    }

    /**
     * Make a new version of uploaded audio with one section of it written
     * again, between `infillStartS` and `infillEndS`.
     *
     * @param  array<string, mixed>  $body
     * @return list<SunoSong>
     */
    public function replaceSection(array $body): array
    {
        return $this->songs('api/v1/generate/replace-section', $body);
    }

    /**
     * Start a task that makes songs and wait for them.
     *
     * @param  array<string, mixed>  $body
     * @return list<SunoSong>
     */
    protected function songs(string $path, array $body): array
    {
        $task = $this->start($path, $body);
        $data = $this->wait('api/v1/generate/record-info', $task, 'status');

        $songs = collect((array) data_get($data, 'response.sunoData', []))
            ->map(fn(array $song) => [
                'task' => $task,
                'id' => (string) ($song['id'] ?? ''),
                'url' => (string) ($song['audioUrl'] ?? $song['audio_url'] ?? ''),
                'title' => $song['title'] ?? null,
                'duration' => isset($song['duration']) ? (float) $song['duration'] : null,
                'image' => $song['imageUrl'] ?? $song['image_url'] ?? null,
                'tags' => $song['tags'] ?? null,
            ])
            ->filter(fn(array $song) => self::isFile($song['url']))
            ->values()
            ->all();

        if (! $songs) {
            throw new SunoException('Suno returned no songs.');
        }

        return $songs;
    }

    /**
     * Split audio into stems and return each part's URL, like
     * ['vocals' => 'https://…', 'backing vocals' => 'https://…'].
     *
     * @param  array<string, mixed>  $body
     * @return array<string, string>
     */
    public function separate(array $body): array
    {
        $task = $this->start('api/v1/vocal-removal/generate', $body);
        $data = $this->wait('api/v1/vocal-removal/record-info', $task, 'successFlag');

        $parts = collect((array) ($data['response'] ?? []))
            ->filter(fn($url, $key) => self::isFile($url) && preg_match('/^(?!origin)\w+(Url|_url)$/i', (string) $key))
            ->mapWithKeys(fn(string $url, string $key) => [$this->partName($key) => $url])
            ->all();

        if (! $parts) {
            throw new SunoException('Suno returned no stems.');
        }

        return $parts;
    }

    /**
     * Upload a local file so Suno can read it, and return its URL. Uploads
     * are deleted after 3 days.
     */
    public function upload(string $path, string $mime = 'audio/mpeg'): string
    {
        $name = Str::random(24) . '.' . (pathinfo($path, PATHINFO_EXTENSION) ?: 'mp3');

        $response = $this
            ->http($this->config['upload_url'] ?? 'https://sunoapiorg.redpandaai.co')
            ->timeout(300)
            ->attach('file', fopen($path, 'r'), $name, ['Content-Type' => $mime])
            ->post('api/file-stream-upload', ['uploadPath' => 'toneflix', 'fileName' => $name]);

        $data = $this->data($response);
        $url = $data['fileUrl'] ?? $data['downloadUrl'] ?? null;

        if (! self::isFile($url)) {
            throw new SunoException('Suno returned no URL for the upload.');
        }

        return $url;
    }

    /**
     * Fetch a file Suno made to a local temporary path.
     */
    public function download(string $url): string
    {
        $path = tempnam(sys_get_temp_dir(), 'suno-');

        $content = Http::timeout(300)
            ->get($url)
            ->throw()
            ->body();

        file_put_contents($path, $content);

        return $path;
    }

    public static function isFile(mixed $value): bool
    {
        return is_string($value) && (str_starts_with($value, 'https://') || str_starts_with($value, 'http://'));
    }

    /**
     * Start a task and return its id.
     *
     * @param  array<string, mixed>  $body
     */
    protected function start(string $path, array $body): string
    {
        $tries = 0;

        while (true) {
            $response = $this
                ->http()
                ->post($path, [...$body, 'callBackUrl' => route('ai.suno.callback')]);

            $code = (int) ($response->json('code') ?? $response->status());

            if (! in_array($code, self::THROTTLED, true) || ++$tries > self::THROTTLE_RETRIES) {
                break;
            }

            Sleep::for(10)->seconds();
        }

        $task = $this->data($response)['taskId'] ?? null;

        if (! $task) {
            throw new SunoException('Suno started no task.');
        }

        return (string) $task;
    }

    /**
     * Poll a task until it succeeds, and return its details.
     *
     * @return array<string, mixed>
     */
    protected function wait(string $path, string $task, string $statusKey): array
    {
        $timeout = (int) ($this->config['timeout'] ?? 600);
        $deadline = now()
            ->addSeconds($timeout);

        while (true) {
            app(AiJobStops::class)->check();

            $data = $this->data($this->http()->get($path, ['taskId' => $task]));
            $status = (string) ($data[$statusKey] ?? 'PENDING');

            if ($status === 'SUCCESS' || ($status === 'CALLBACK_EXCEPTION' && ! empty($data['response']))) {
                return $data;
            }

            if (in_array($status, self::FAILED, true) || $status === 'CALLBACK_EXCEPTION') {
                throw new SunoException(trim("Suno task {$status}. " . ($data['errorMessage'] ?? '')));
            }

            if (now()->greaterThan($deadline)) {
                throw new SunoException("Suno took longer than {$timeout} seconds.");
            }

            Sleep::for(self::POLL_SECONDS)->seconds();
        }
    }

    /**
     * The body's data, or an error when Suno answered with anything but 200.
     *
     * @return array<string, mixed>
     */
    protected function data(Response $response): array
    {
        $response->throw();

        $code = (int) ($response->json('code') ?? 200);

        if ($code !== 200) {
            throw new SunoException("Suno answered {$code}: " . ($response->json('msg') ?? $response->body()));
        }

        return (array) $response->json('data');
    }

    /**
     * A stem's name from its field, like "backing vocals" for backingVocalsUrl.
     */
    protected function partName(string $key): string
    {
        $name = str($key)
            ->replaceMatches('/(Url|_url)$/i', '')
            ->snake(' ')
            ->replace('_', ' ')
            ->lower()
            ->toString();

        return $name === 'vocal' ? 'vocals' : $name;
    }

    protected function http(?string $url = null): PendingRequest
    {
        if (blank($this->config['key'] ?? null)) {
            throw new SunoException('Set SUNO_API_KEY in .env to use Suno.');
        }

        return Http::baseUrl(rtrim($url ?? (string) ($this->config['url'] ?? 'https://api.sunoapi.org'), '/') . '/')
            ->withToken((string) $this->config['key'])
            ->acceptJson()
            ->timeout(60);
    }
}
