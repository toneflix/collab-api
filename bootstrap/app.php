<?php

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\ResolvePlaceholderUrls;
use App\Http\Middleware\SecureAsset;
use App\Http\Middleware\VerifyCsrfToken;
use App\Http\Middleware\VerifyRelaySignature;
use App\Services\ExceptionHandler;
use Faker\Factory;
use Faker\Generator;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use ToneflixCode\FakerLoremToneflix\FakerLoremToneflixProvider;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    // Listeners are registered in EventServiceProvider; discovering them too runs each one twice.
    ->withEvents(discover: false)
    ->withSingletons([
        Generator::class => function () {
            $faker = Factory::create();
            $faker->addProvider(new FakerLoremToneflixProvider($faker));

            return $faker;
        },
    ])
    ->withBindings(
        [
            Generator::class . ':en_NG' => Generator::class,
            Generator::class . ':en_GB' => Generator::class,
            Generator::class . ':en_US' => Generator::class,
        ]
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();

        $middleware->preventRequestsDuringMaintenance();

        $middleware->convertEmptyStringsToNull();

        $middleware->trimStrings(
            except: [
                'current_password',
                'password',
                'password_confirmation',
            ]
        );

        // Hosts like Railway end TLS at their edge, so trust its X-Forwarded-*
        // headers. Otherwise links are built as http:// and browsers block them.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*'),
            headers: Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_HOST |
                Request::HEADER_X_FORWARDED_PORT |
                Request::HEADER_X_FORWARDED_PROTO |
                Request::HEADER_X_FORWARDED_AWS_ELB
        );

        $middleware->append(ResolvePlaceholderUrls::class);

        $middleware->web(prepend: [
            EncryptCookies::class,
            VerifyCsrfToken::class,
        ]);

        $middleware->api(append: [
            EnsureFrontendRequestsAreStateful::class,
            ThrottleRequests::class . ':api',
            SubstituteBindings::class,
        ]);

        $middleware->alias([
            'auth' => Authenticate::class,
            'verified' => EnsureEmailIsVerified::class,
            'secure-asset' => SecureAsset::class,
            'guest' => RedirectIfAuthenticated::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'relay' => VerifyRelaySignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        if (config('app.testing', false) === false) {
            $exceptions->render(function (Throwable $e, Request $request) {
                return (new ExceptionHandler)->render($request, $e);
            });
        }
    })->create();
