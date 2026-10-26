<?php

namespace Tests\Feature;

use App\Models\Configuration;
use App\Models\PasswordCodeResets;
use App\Models\User;
use App\Notifications\SendCode;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuthFlowsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    protected function setting(string $key, mixed $value, string $type = 'boolean'): void
    {
        $configuration = Configuration::query()
            ->firstOrNew(['key' => $key]);

        $configuration->forceFill(['key' => $key, 'type' => $type, 'title' => $key]);
        $configuration->value = $value;
        $configuration->save();

        Cache::forget('configuration::build');
    }

    public function test_a_reset_code_lets_the_user_set_a_new_password(): void
    {
        $user = User::factory()
            ->create();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertCreated()
            ->assertJsonStructure(['try_at', 'time_left']);

        $reset = PasswordCodeResets::query()
            ->where('email', $user->email)
            ->firstOrFail();

        Notification::assertSentTo($reset, SendCode::class);

        $this->postJson('/api/auth/reset-password/check-code', ['code' => $reset->code])
            ->assertAccepted()
            ->assertJsonPath('data.valid', true);

        $this->postJson('/api/auth/reset-password', [
            'code' => $reset->code,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])
            ->assertAccepted();

        $this->assertTrue(Hash::check('NewPassword123!', $user->fresh()->password));
        $this->assertDatabaseMissing('password_code_resets', ['email' => $user->email]);
    }

    public function test_the_emailed_link_token_is_accepted(): void
    {
        $user = User::factory()
            ->create();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertCreated();

        $reset = PasswordCodeResets::query()
            ->where('email', $user->email)
            ->firstOrFail();

        $this->postJson('/api/auth/reset-password/check-code', ['code' => $reset->token])
            ->assertAccepted();

        $this->postJson('/api/auth/reset-password', [
            'code' => $reset->token,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])
            ->assertAccepted();
    }

    public function test_expired_reset_codes_are_rejected(): void
    {
        $this->setting('token_lifespan', 300, 'number');

        $user = User::factory()
            ->create();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertCreated();

        $reset = PasswordCodeResets::query()
            ->where('email', $user->email)
            ->firstOrFail();

        $this->travel(301)->seconds();

        $this->postJson('/api/auth/reset-password/check-code', ['code' => $reset->code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_sign_up_sends_a_verification_code_when_required(): void
    {
        $this->setting('verify_email', true);

        $email = 'verify-' . uniqid() . '@example.com';

        $this->postJson('/api/auth/register', [
            'firstname' => 'Ada',
            'lastname' => 'Okafor',
            'email' => $email,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'accept' => true,
        ])
            ->assertCreated();

        $user = User::query()
            ->where('email', $email)
            ->firstOrFail();

        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->email_verify_code);
        Notification::assertSentTo($user, SendCode::class);
    }

    public function test_a_verification_code_confirms_the_email(): void
    {
        $this->setting('verify_email', true);

        $user = User::factory()
            ->create(['email_verified_at' => null]);

        $user->sendEmailVerificationNotification();

        Sanctum::actingAs($user);

        $this->putJson('/api/verify/with-code/email', ['code' => '000000x'])
            ->assertUnprocessable();

        $this->putJson('/api/verify/with-code/email', ['code' => $user->fresh()->email_verify_code])
            ->assertAccepted()
            ->assertJsonPath('verified.email', true);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_a_phone_code_confirms_the_phone(): void
    {
        $user = User::factory()
            ->create(['phone' => '+2348012345678', 'phone_verified_at' => null]);

        $user->sendPhoneVerificationNotification();

        Sanctum::actingAs($user);

        $this->putJson('/api/verify/with-code/phone', ['code' => $user->fresh()->phone_verify_code])
            ->assertAccepted()
            ->assertJsonPath('verified.phone', true);
    }

    public function test_admins_customise_the_auth_pages(): void
    {
        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();
        Permission::findOrCreate('manage-configuration', 'web');

        $admin = User::factory()
            ->create();
        $admin->givePermissionTo('manage-configuration');

        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/auth-pages', [
            'layout' => '40-60',
            'texts' => ['login.title' => 'Hello again', 'login.unknown' => 'x'],
        ])
            ->assertAccepted()
            ->assertJsonPath('data.layout', '40-60');

        $this->getJson('/api/initialize')
            ->assertOk()
            ->assertJsonPath('data.auth.layout', '40-60')
            ->assertJsonPath('data.auth.split.form', 40)
            ->assertJsonPath('data.auth.texts.login.title', 'Hello again')
            ->assertJsonPath('data.auth.texts.login.submit', 'Sign in')
            ->assertJsonMissingPath('data.configuration.auth_pages');

        $this->putJson('/api/admin/auth-pages', ['texts' => ['login.title' => '']])
            ->assertAccepted();

        $this->getJson('/api/initialize')
            ->assertJsonPath('data.auth.texts.login.title', 'Welcome back');

        $this->putJson('/api/admin/auth-pages', ['layout' => 'sideways'])
            ->assertUnprocessable();
    }

    public function test_only_admins_customise_the_auth_pages(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/auth-pages')
            ->assertForbidden();
    }
}
