<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Composer\ComposerSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ComposerRateLimitTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        ComposerSettings::update(['composer_word_source' => 'datamuse']);

        parent::tearDown();
    }

    public function test_composer_requests_use_their_own_limit(): void
    {
        ComposerSettings::update(['composer_word_source' => 'local']);
        $user = User::factory()
            ->create();
        Sanctum::actingAs($user);

        for ($i = 0; $i < 70; $i++) {
            $this->postJson('/api/words/syllables', ['words' => ['night']])
                ->assertOk()
                ->assertHeader('X-RateLimit-Limit', 300);
        }

        $this->getJson('/api/creator/songs')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', 300);

        $this->assertSame(0, RateLimiter::attempts(md5('api' . $user->id)));
    }
}
