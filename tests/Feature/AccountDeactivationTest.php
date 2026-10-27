<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use App\Services\Account\Deactivation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountDeactivationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    protected function release(User $owner): Release
    {
        $genre = Genre::factory()
            ->create();
        $artist = Artist::factory()
            ->create();

        return Release::factory()
            ->has(Track::factory()->count(1)->state(['genre_id' => $genre->id]), 'tracks')
            ->create([
                'draft' => false,
                'genre_id' => $genre->id,
                'user_id' => $owner->id,
                'artist_id' => $artist->id,
            ]);
    }

    public function test_deactivating_needs_the_password(): void
    {
        $user = User::factory()
            ->create();

        Sanctum::actingAs($user);

        $this->postJson('/api/account/deactivate', ['password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertNull($user->fresh()->deactivated_at);
    }

    public function test_deactivating_signs_out_and_hides_the_account(): void
    {
        $user = User::factory()
            ->create();
        $release = $this->release($user);
        $track = $release->tracks()->first();

        $user->createToken('phone');

        Sanctum::actingAs($user);

        $this->postJson('/api/account/deactivate', ['password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.deletesAt', null);

        $fresh = User::query()
            ->withoutGlobalScopes()
            ->find($user->id);

        $this->assertNotNull($fresh->deactivated_at);
        $this->assertNull($fresh->deletes_at);
        $this->assertSame(0, $fresh->tokens()->count());

        $this->app['auth']->forgetGuards();

        $this->getJson("/api/users/{$user->username}")
            ->assertNotFound();
        $this->getJson("/api/releases/{$release->slug}")
            ->assertNotFound();
        $this->getJson("/api/tracks/{$track->slug}")
            ->assertNotFound();
    }

    public function test_deleting_sets_a_date_from_the_grace_period(): void
    {
        $user = User::factory()
            ->create();

        Sanctum::actingAs($user);

        $this->postJson('/api/account/delete', ['password' => 'password'])
            ->assertOk();

        $days = Deactivation::graceDays();

        $scheduled = User::query()
            ->withoutGlobalScopes()
            ->whereKey($user->id)
            ->whereNotNull('deactivated_at')
            ->whereBetween('deletes_at', [now()->addDays($days)->subMinute(), now()->addDays($days)->addMinute()])
            ->exists();

        $this->assertTrue($scheduled);
    }

    public function test_signing_in_brings_the_account_back(): void
    {
        $user = User::factory()
            ->create();

        Deactivation::deactivate($user, delete: true);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('message', 'Welcome back. Your account is active again.');

        $fresh = $user->fresh();

        $this->assertNotNull($fresh);
        $this->assertNull($fresh->deactivated_at);
        $this->assertNull($fresh->deletes_at);
    }

    public function test_purge_erases_only_accounts_past_their_date(): void
    {
        $due = User::factory()
            ->create();
        $waiting = User::factory()
            ->create();
        $deactivated = User::factory()
            ->create();

        Deactivation::deactivate($due, delete: true);
        Deactivation::deactivate($waiting, delete: true);
        Deactivation::deactivate($deactivated);

        $due
            ->forceFill(['deletes_at' => now()->subMinute()])
            ->save();

        $this->artisan('accounts:purge')
            ->assertSuccessful();

        $remaining = User::query()
            ->withoutGlobalScopes()
            ->whereKey([$due->id, $waiting->id, $deactivated->id])
            ->pluck('id')
            ->all();

        $this->assertEqualsCanonicalizing([$waiting->id, $deactivated->id], $remaining);
    }
}
