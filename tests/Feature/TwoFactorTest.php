<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\Totp;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()
            ->create(['password' => 'secret-pass']);
    }

    public function test_codes_match_the_rfc_6238_examples(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');

        $this->assertSame('287082', Totp::code($secret, Totp::step(59)));
        $this->assertSame('005924', Totp::code($secret, Totp::step(1234567890)));
        $this->assertSame(Totp::step(1234567890), Totp::match($secret, '005924', 1234567890));
        $this->assertNull(Totp::match($secret, '005924', 1234567890 + 120));
    }

    /**
     * Turn two-factor sign-in on and return the secret and recovery codes.
     *
     * @return array{0: string, 1: list<string>}
     */
    protected function enable(): array
    {
        Sanctum::actingAs($this->user);

        $this
            ->postJson('/api/account/two-factor', ['password' => 'wrong'])
            ->assertJsonValidationErrors(['password']);

        $secret = $this
            ->postJson('/api/account/two-factor', ['password' => 'secret-pass'])
            ->assertOk()
            ->assertJsonPath('data.uri', fn(string $uri) => str_starts_with($uri, 'otpauth://totp/'))
            ->json('data.secret');

        $this
            ->postJson('/api/account/two-factor/confirm', ['code' => '000000'])
            ->assertJsonValidationErrors(['code']);

        $codes = $this
            ->postJson('/api/account/two-factor/confirm', ['code' => Totp::code($secret, Totp::step())])
            ->assertAccepted()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonCount(8, 'data.recoveryCodes')
            ->json('data.recoveryCodes');

        RateLimiter::clear('two-factor:' . $this->user->id);

        return [$secret, $codes];
    }

    public function test_users_can_turn_it_on_and_sign_in_with_a_code(): void
    {
        [$secret] = $this->enable();

        $this->assertNotSame($secret, $this->user->fresh()->getRawOriginal('two_factor_secret'));

        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        $challenge = $this
            ->postJson('/api/auth/login', ['email' => $this->user->email, 'password' => 'secret-pass'])
            ->assertAccepted()
            ->assertJsonMissingPath('token')
            ->assertJsonPath('data.twoFactor', true)
            ->json('data.challenge');

        // The code used to turn it on can't be used again.
        $this
            ->postJson('/api/auth/login/two-factor', ['challenge' => $challenge, 'code' => Totp::code($secret, Totp::step())])
            ->assertJsonValidationErrors(['code']);

        $this
            ->postJson('/api/auth/login/two-factor', ['challenge' => $challenge, 'code' => Totp::code($secret, Totp::step() + 1)])
            ->assertOk()
            ->assertJsonPath('data.twoFactor', true)
            ->assertJsonStructure(['token']);

        $this->app['auth']->forgetGuards();

        $this
            ->postJson('/api/auth/login/two-factor', ['challenge' => $challenge, 'code' => '123456'])
            ->assertJsonValidationErrors(['challenge']);
    }

    public function test_recovery_codes_work_once(): void
    {
        [, $codes] = $this->enable();

        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        $challenge = $this
            ->postJson('/api/auth/login', ['email' => $this->user->email, 'password' => 'secret-pass'])
            ->json('data.challenge');

        $this
            ->postJson('/api/auth/login/two-factor', ['challenge' => $challenge, 'code' => strtoupper($codes[0])])
            ->assertOk();

        $this->assertSame(7, count($this->user->fresh()->two_factor_recovery_codes));

        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        $challenge = $this
            ->postJson('/api/auth/login', ['email' => $this->user->email, 'password' => 'secret-pass'])
            ->json('data.challenge');

        $this
            ->postJson('/api/auth/login/two-factor', ['challenge' => $challenge, 'code' => $codes[0]])
            ->assertJsonValidationErrors(['code']);
    }

    public function test_wrong_codes_are_limited(): void
    {
        $this->enable();

        foreach (range(1, 5) as $attempt) {
            $this
                ->deleteJson('/api/account/two-factor', ['code' => '000000'])
                ->assertJsonValidationErrors(['code' => 'That code is not correct.']);
        }

        $this
            ->deleteJson('/api/account/two-factor', ['code' => '000000'])
            ->assertJsonValidationErrors(['code' => 'Too many wrong codes.']);
    }

    public function test_users_can_turn_it_off_with_a_code(): void
    {
        [, $codes] = $this->enable();

        $this
            ->deleteJson('/api/account/two-factor', ['code' => $codes[1]])
            ->assertAccepted()
            ->assertJsonPath('data.enabled', false);

        $this->assertNull($this->user->fresh()->two_factor_secret);

        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        $this
            ->postJson('/api/auth/login', ['email' => $this->user->email, 'password' => 'secret-pass'])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }
}
