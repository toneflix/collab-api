<?php

namespace Tests\Feature;

use App\Services\Mail\SendPulseTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class SendPulseMailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.sendpulse.id' => 'sp-id',
            'services.sendpulse.secret' => 'sp-secret',
            'mail.from' => ['address' => 'hello@toneflix.net', 'name' => 'Toneflix Music'],
        ]);

        Cache::forget(SendPulseTransport::TOKEN_CACHE_KEY);
    }

    public function test_it_sends_mail_through_the_api(): void
    {
        Http::fake([
            'api.sendpulse.com/oauth/access_token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
            'api.sendpulse.com/smtp/emails' => Http::response(['result' => true, 'id' => 'em-1']),
        ]);

        $this->send();

        Http::assertSent(fn(Request $request) => $request->url() === 'https://api.sendpulse.com/oauth/access_token'
            && $request['client_id'] === 'sp-id'
            && $request['client_secret'] === 'sp-secret');

        Http::assertSent(function (Request $request) {
            if ($request->url() !== 'https://api.sendpulse.com/smtp/emails') {
                return false;
            }

            $email = $request['email'];

            return $request->hasHeader('Authorization', 'Bearer tok-1')
                && $email['subject'] === 'Your new collab'
                && base64_decode($email['html']) === '<p>Hello Ada</p>'
                && $email['from'] === ['name' => 'Toneflix Music', 'email' => 'hello@toneflix.net']
                && $email['to'] === [['name' => 'Ada', 'email' => 'ada@example.com']]
                && $email['bcc'] === [['email' => 'audit@example.com']]
                && base64_decode($email['attachments_binary']['notes.txt']) === 'session notes';
        });

        $this->assertSame('tok-1', Cache::get(SendPulseTransport::TOKEN_CACHE_KEY));
    }

    public function test_it_reuses_the_cached_token_and_refreshes_it_once_when_rejected(): void
    {
        Cache::put(SendPulseTransport::TOKEN_CACHE_KEY, 'stale', 600);

        Http::fake([
            'api.sendpulse.com/oauth/access_token' => Http::response(['access_token' => 'fresh', 'expires_in' => 3600]),
            'api.sendpulse.com/smtp/emails' => Http::sequence()
                ->push(['message' => 'Unauthorized'], 401)
                ->push(['result' => true, 'id' => 'em-2']),
        ]);

        $this->send();

        Http::assertSentCount(3);
        Http::assertSent(fn(Request $request) => $request->url() === 'https://api.sendpulse.com/smtp/emails'
            && $request->hasHeader('Authorization', 'Bearer fresh'));
    }

    public function test_a_rejected_email_fails_the_send(): void
    {
        Http::fake([
            'api.sendpulse.com/oauth/access_token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
            'api.sendpulse.com/smtp/emails' => Http::response(['result' => false, 'message' => 'Sender is not verified'], 422),
        ]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Sender is not verified');

        $this->send();
    }

    protected function send(): void
    {
        Mail::mailer('sendpulse')->html('<p>Hello Ada</p>', function (Message $message) {
            $message
                ->to('ada@example.com', 'Ada')
                ->bcc('audit@example.com')
                ->subject('Your new collab')
                ->attachData('session notes', 'notes.txt');
        });
    }
}
