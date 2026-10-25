<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Points the placeholder images in public/defaults at the host serving the request,
 * so they never depend on APP_URL being set correctly.
 */
class ResolvePlaceholderUrls
{
    private const DIRECTORY = 'defaults/';

    public function handle(Request $request, Closure $next): Response
    {
        $collections = collect(config('toneflix-fileable.collections', []))
            ->map(fn(array $collection) => $this->resolve($collection));

        config(['toneflix-fileable.collections' => $collections->all()]);

        return $next($request);
    }

    private function resolve(array $collection): array
    {
        $default = $collection['default'] ?? null;

        if (is_string($default) && str_contains($default, '/' . self::DIRECTORY)) {
            $collection['default'] = asset(self::DIRECTORY . basename($default));
        }

        return $collection;
    }
}
