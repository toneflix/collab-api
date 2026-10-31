<?php

namespace Tests\Feature;

use App\Helpers\Providers;
use App\Mail\TestMail;
use App\Models\Configuration;
use App\Models\User;
use App\Services\Account\Deactivation;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class ConfigurationTest extends TestCase
{
    /**
     * Controllers keep the user they were built with, so drop them between users.
     */
    protected function actingAsFresh(User $user): User
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->controller = null;
        }

        $user = $user->fresh();
        Sanctum::actingAs($user);

        return $user;
    }

    protected function admin(): User
    {
        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();
        Permission::findOrCreate('manage-configuration', 'web');

        $admin = User::factory()
            ->create();
        $admin->givePermissionTo('manage-configuration');

        return $admin;
    }

    public function test_only_admins_see_the_configuration(): void
    {
        $this->actingAsFresh(User::factory()->create());
        $this
            ->getJson('/api/admin/configuration')
            ->assertForbidden();

        $this->actingAsFresh($this->admin());
        $this->getJson('/api/admin/configuration')
            ->assertOk()
            ->assertJsonPath('data.fields.app_currency.title', 'App Currency')
            ->assertJsonPath('data.fields.app_currency.options.0', 'NGN')
            ->assertJsonPath('data.fields.payout_auto_approve.type', 'boolean');
    }

    public function test_admins_change_the_configuration(): void
    {
        $this->actingAsFresh($this->admin());

        $this->putJson('/api/admin/configuration', [
            'app_currency' => 'GHS',
            'creator_share' => 90,
            'payout_auto_approve' => true,
            'verify_email' => false,
        ])
            ->assertAccepted()
            ->assertJsonPath('data.fields.app_currency.value', 'GHS')
            ->assertJsonPath('data.fields.creator_share.value', 90)
            ->assertJsonPath('data.fields.payout_auto_approve.value', true)
            ->assertJsonPath('data.fields.verify_email.value', false);

        $this->assertSame('GHS', Providers::config('app_currency'));
        $this->assertSame(90, Providers::config('creator_share'));
        $this->assertTrue(Providers::config('payout_auto_approve'));
    }

    public function test_invalid_values_are_rejected(): void
    {
        $this->actingAsFresh($this->admin());

        $this->putJson('/api/admin/configuration', ['app_currency' => 'XYZ', 'creator_share' => 120])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['app_currency', 'creator_share']);
    }

    public function test_a_missing_setting_is_created_with_its_type(): void
    {
        Configuration::where('key', 'payout_hold_days')
            ->delete();
        $this->actingAsFresh($this->admin());

        $this
            ->putJson('/api/admin/configuration', ['payout_hold_days' => 3])
            ->assertAccepted();

        $row = Configuration::where('key', 'payout_hold_days')
            ->first();
        $this->assertSame('number', $row->type);
        $this->assertSame(3, $row->value);
    }

    public function test_admins_set_and_clear_the_discord_link(): void
    {
        $this->actingAsFresh($this->admin());

        $this->putJson('/api/admin/configuration', ['discord_url' => 'https://discord.gg/toneflix'])
            ->assertAccepted()
            ->assertJsonPath('data.fields.discord_url.value', 'https://discord.gg/toneflix');

        $this->assertSame('https://discord.gg/toneflix', Providers::config('discord_url'));

        $this->putJson('/api/admin/configuration', ['discord_url' => 'not a link'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['discord_url']);

        $this->putJson('/api/admin/configuration', ['discord_url' => ''])
            ->assertAccepted();

        $this->assertEmpty(Providers::config('discord_url'));
    }

    public function test_admins_set_the_account_deletion_grace_period(): void
    {
        $this->actingAsFresh($this->admin());

        $this->getJson('/api/admin/configuration')
            ->assertOk()
            ->assertJsonPath('data.fields.account_deletion_days.type', 'number');

        $this->putJson('/api/admin/configuration', ['account_deletion_days' => 14])
            ->assertAccepted()
            ->assertJsonPath('data.fields.account_deletion_days.value', 14);

        $this->assertSame(14, Deactivation::graceDays());

        $this->putJson('/api/admin/configuration', ['account_deletion_days' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_deletion_days']);
    }

    public function test_admins_send_a_test_email(): void
    {
        Mail::fake();

        $this->actingAsFresh(User::factory()->create());
        $this
            ->postJson('/api/admin/configuration/test-mail', ['email' => 'ops@example.com'])
            ->assertForbidden();

        $this->actingAsFresh($this->admin());
        $this
            ->postJson('/api/admin/configuration/test-mail', ['email' => 'not-an-email'])
            ->assertUnprocessable();

        $this
            ->postJson('/api/admin/configuration/test-mail', ['email' => 'ops@example.com'])
            ->assertOk()
            ->assertJsonPath('data.email', 'ops@example.com')
            ->assertJsonPath('message', 'Test email sent to ops@example.com.');

        Mail::assertSent(TestMail::class, fn(TestMail $mail) => $mail->hasTo('ops@example.com'));
    }

    public function test_a_failed_test_email_shows_the_mailer_error(): void
    {
        Mail::shouldReceive('to->send')
            ->andThrow(new TransportException('Sender is not verified'));

        $this->actingAsFresh($this->admin());
        $this
            ->postJson('/api/admin/configuration/test-mail', ['email' => 'ops@example.com'])
            ->assertStatus(502)
            ->assertJsonPath('message', 'The test email could not be sent: Sender is not verified');
    }

    public function test_the_configuration_shows_the_mailer(): void
    {
        config(['mail.default' => 'sendpulse', 'mail.from.address' => 'hello@toneflix.net']);

        $this->actingAsFresh($this->admin());
        $this
            ->getJson('/api/admin/configuration')
            ->assertOk()
            ->assertJsonPath('data.mail.mailer', 'sendpulse')
            ->assertJsonPath('data.mail.from', 'hello@toneflix.net');
    }
}
