<?php

namespace App\Http\Middleware;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use Closure;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Sign people in, then turn away suspended accounts.
     */
    public function handle($request, Closure $next, ...$guards)
    {
        return parent::handle($request, function (Request $request) use ($next) {
            abort_if($request->user()?->isSuspended(), HttpStatus::FORBIDDEN->value, $request->user()?->suspensionMessage() ?? '');

            return $next($request);
        }, ...$guards);
    }

    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        return $request->expectsJson()
        ? Providers::response()->error([
            'message' => 'please login to continue.',
        ], HttpStatus::FORBIDDEN)
        : route('login');
    }
}
