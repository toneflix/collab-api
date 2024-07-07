<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'data' => [
                "fake" => true,
                "settings" => [
                    "notifications" => [
                        "email" => true
                    ]
                ],
                "eraseOnDelete" => true,
            ],
            'image' => $this->faker->imageUrl(480, 480, 'Music', true, null, true, 'jpg'),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->phoneNumber(),
            'username' => str(fake()->unique()->userName())->slug()->toString(),
            'lastname' => fake()->lastName,
            'password' => static::$password ??= Hash::make('password'),
            'firstname' => fake()->firstName,
            'remember_token' => Str::random(10),
            'email_verified_at' => now(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $model) {
            User::inRandomOrder()->limit(rand(1, 14))
                ->get()
                ->each(function (User $user, int $i) use ($model) {
                    $i < 7 && $model->follow($user);
                    $i > 6 && $user->follow($model);
                });
        });
    }
}