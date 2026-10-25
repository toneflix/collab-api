<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    public function test_links_use_https_behind_a_tls_proxy(): void
    {
        Route::get('/_proxy-url-check', fn() => url('/media/files/x'));

        $this->get('http://api.test/_proxy-url-check', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'api.test',
            'X-Forwarded-Port' => '443',
        ])->assertOk()->assertSee('https://api.test/media/files/x', false);
    }
}
