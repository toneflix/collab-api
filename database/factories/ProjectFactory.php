<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => $this->faker->slug,
            'image' => $this->faker->imageUrl(480, 480, 'album', true),
            'title' => ucwords($this->faker->words(rand(2, 4), true)),
            'privacy' => 'public',
            'description' => $this->faker->sentences(rand(2, 3), true),
        ];
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Project $model) {
            User::inRandomOrder()->limit(rand(1, 10))
                ->get()
                ->each(function (User $user, $i) use ($model) {
                    $model->giveVote($user);
                    $i < rand(2, 6) && $model->collaborators()->create([
                        'role' => 'Lead',
                        'user_id' => $user->id,
                    ]);
                });
        });
    }
}
