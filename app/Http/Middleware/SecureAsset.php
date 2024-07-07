<?php

namespace App\Http\Middleware;

use App\Enums\HttpStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecureAsset
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $reponse = $next($request);

        if ($request->url() === $request->header('referer')) {
            abort(HttpStatus::FORBIDDEN->value);
        }

        return $reponse;
    }
}
