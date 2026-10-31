<?php

namespace App\Services\Mail;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

/**
 * Sends mail through the SendPulse SMTP API over HTTPS.
 */
class SendPulseTransport extends AbstractTransport
{
    public const TOKEN_CACHE_KEY = 'mail:sendpulse:token';

    public function __construct(
        protected Http $http,
        protected Cache $cache,
        protected string $id,
        protected string $secret,
        protected string $url = 'https://api.sendpulse.com',
        protected int $timeout = 30,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $payload = ['email' => $this->payload($email)];

        $response = $this->post($payload);

        if ($response->status() === 401) {
            $this->cache->forget(self::TOKEN_CACHE_KEY);
            $response = $this->post($payload);
        }

        if ($response->failed() || ! $response->json('result')) {
            throw new TransportException(
                'SendPulse could not send the email: ' . ($response->json('message') ?? $response->body()),
                $response->status(),
            );
        }

        if ($id = $response->json('id')) {
            $message->setMessageId((string) $id);
        }
    }

    /**
     * The `email` object the SendPulse API takes.
     */
    protected function payload(Email $email): array
    {
        $from = $email->getFrom()[0] ?? null;

        return array_filter([
            'subject' => (string) $email->getSubject(),
            'html' => base64_encode((string) $email->getHtmlBody()),
            'text' => (string) $email->getTextBody(),
            'from' => $from ? $this->address($from) : null,
            'to' => array_map($this->address(...), $email->getTo()),
            'cc' => array_map($this->address(...), $email->getCc()),
            'bcc' => array_map($this->address(...), $email->getBcc()),
            'attachments_binary' => $this->attachments($email),
        ], fn($value) => $value !== null && $value !== []);
    }

    protected function address(Address $address): array
    {
        return array_filter([
            'name' => $address->getName(),
            'email' => $address->getAddress(),
        ]);
    }

    /**
     * Attachments keyed by file name, base64 encoded.
     */
    protected function attachments(Email $email): array
    {
        $files = [];

        foreach ($email->getAttachments() as $index => $attachment) {
            $name = $attachment->getFilename() ?? 'attachment-' . ($index + 1);
            $files[$name] = base64_encode($attachment->getBody());
        }

        return $files;
    }

    protected function post(array $payload): Response
    {
        try {
            return $this->client()
                ->withToken($this->token())
                ->post('smtp/emails', $payload);
        } catch (ConnectionException $e) {
            throw new TransportException('SendPulse is unreachable: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * An OAuth access token, cached until shortly before it expires.
     */
    protected function token(): string
    {
        $token = $this->cache->get(self::TOKEN_CACHE_KEY);

        if ($token) {
            return $token;
        }

        $response = $this->client()->post('oauth/access_token', [
            'grant_type' => 'client_credentials',
            'client_id' => $this->id,
            'client_secret' => $this->secret,
        ]);

        $token = $response->json('access_token');

        if ($response->failed() || ! $token) {
            throw new TransportException(
                'SendPulse rejected the API credentials: ' . ($response->json('message') ?? $response->body()),
                $response->status(),
            );
        }

        $ttl = max((int) $response->json('expires_in', 3600) - 60, 60);

        $this->cache->put(self::TOKEN_CACHE_KEY, $token, $ttl);

        return $token;
    }

    protected function client(): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim($this->url, '/'))
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);
    }

    public function __toString(): string
    {
        return 'sendpulse';
    }
}
