<?php

namespace App\Http\Middleware;

use App\Enums\HttpStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a gateway webhook through only when the Toneflix payment relay signed it,
 * once a relay secret is set. The relay sends `X-Relay-Signature`, an HMAC-SHA256
 * of the raw body keyed by this app's relay secret, beside the gateway's own headers.
 */
class VerifyRelaySignature
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.relay.secret');

        if ($secret !== '') {
            $expected = hash_hmac('sha256', $request->getContent(), $secret);

            abort_unless(
                hash_equals($expected, (string) $request->header('X-Relay-Signature')),
                HttpStatus::UNAUTHORIZED->value,
                'Webhooks are accepted only from the payment relay.',
            );
        }

        return $next($request);
    }
}
