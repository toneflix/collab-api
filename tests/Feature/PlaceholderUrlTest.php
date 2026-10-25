<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PlaceholderUrlTest extends TestCase
{
    public function test_default_avatar_uses_the_request_host_not_app_url(): void
    {
        config(['app.url' => 'http://localhost']);

        Route::get('/_placeholder-check', fn() => (new User(['image' => 'missing.png']))->images['image']);

        $this->get('http://collab-api.test/_placeholder-check', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'collab-api.test',
            'X-Forwarded-Port' => '443',
        ])->assertOk()->assertSeeText('https://collab-api.test/defaults/avatar.svg', false);
    }
}
