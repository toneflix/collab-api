<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

/**
 * The Android app runs at https://localhost and signs in with API tokens.
 * Sanctum must not treat it as a cookie (stateful) client, or every POST,
 * sign-in included, fails with "CSRF token mismatch".
 */
class AppOriginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // As in production: the API has its own domain. Re-read the config with it.
        config(['app.url' => 'https://api.toneflix.test']);
        config(['sanctum' => require config_path('sanctum.php')]);
    }

    public function test_the_installed_app_is_not_a_cookie_client(): void
    {
        $request = Request::create('/api/auth/login', 'POST');
        $request->headers->set('Origin', 'https://localhost');

        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }

    public function test_the_app_url_can_still_use_cookies(): void
    {
        $request = Request::create('/api/auth/login', 'POST');
        $request->headers->set('Origin', 'https://api.toneflix.test');

        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }
}
