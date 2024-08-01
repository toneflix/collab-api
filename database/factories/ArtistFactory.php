<?php

namespace Database\Factories;

use App\Models\Artist;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Artist>
 */
class ArtistFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name,
            'about' => $this->faker->sentences(rand(2, 3), true),
            'image' => $this->faker->imageUrl(480, 480, 'avatar', true, null, true),
            'verified' => fake()->boolean(),
            'username' => str($this->faker->unique()->userName())->slug()->toString() . rand(0, 9),
        ];
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Artist $model) {
            Release::factory()
                ->sequence(fn () => [
                    'artist_id' => $model->id,
                    'user_id' => User::where('data->fake', true)->inRandomOrder()->first()->id,
                ])
                ->has(Track::factory()->count(rand(3, 5)), 'tracks')
                ->count(rand(1, 3))
                ->create();
        });
    }
}
