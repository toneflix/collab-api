<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserProfileTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_profile_hides_contact_details(): void
    {
        $user = User::factory()
            ->create(['about' => 'I make beats.']);

        $this->getJson("/api/users/{$user->username}")
            ->assertOk()
            ->assertJsonPath('data.username', $user->username)
            ->assertJsonPath('data.about', 'I make beats.')
            ->assertJsonMissingPath('data.email')
            ->assertJsonMissingPath('data.phone');
    }

    public function test_owner_sees_their_contact_details(): void
    {
        $user = User::factory()
            ->create();

        Sanctum::actingAs($user);

        $this->getJson("/api/users/{$user->username}")
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_owner_can_change_username_and_about(): void
    {
        $user = User::factory()
            ->create();

        Sanctum::actingAs($user);

        $this->putJson('/api/account/profile', [
            'username' => 'new_name-1',
            'about' => 'Songwriter from Lagos.',
        ])
            ->assertStatus(202)
            ->assertJsonPath('data.username', 'new_name-1')
            ->assertJsonPath('data.about', 'Songwriter from Lagos.');
    }

    public function test_username_must_be_unique_and_url_safe(): void
    {
        $taken = User::factory()
            ->create();
        $user = User::factory()
            ->create();

        Sanctum::actingAs($user);

        $this->putJson('/api/account/profile', ['username' => $taken->username])
            ->assertStatus(422)
            ->assertJsonValidationErrors('username');

        $this->putJson('/api/account/profile', ['username' => 'has space'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('username');
    }
}
