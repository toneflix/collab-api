<?php

namespace Database\Factories;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
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
                'fake' => true,
                'settings' => [
                    'notifications' => [
                        'email' => true,
                    ],
                ],
                'eraseOnDelete' => true,
            ],
            'image' => $this->faker->imageUrl(480, 480, 'avatar', true),
            'email' => $this->faker->unique()->email(),
            'phone' => $this->faker->unique()->phoneNumber(),
            'city' => $this->faker->city(),
            'about' => $this->faker->sentences(rand(2, 3), true),
            'state' => $this->faker->city(),
            'country' => $this->faker->country(),
            'username' => str($this->faker->unique()->userName())->slug()->toString() . rand(01, 99),
            'lastname' => $this->faker->lastName,
            'password' => static::$password ??= Hash::make('password'),
            'verified' => fake()->boolean(),
            'firstname' => $this->faker->firstName,
            'remember_token' => Str::random(10),
            'email_verified_at' => now(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn(array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $model) {
            $count = rand(1, 14);

            User::inRandomOrder()->limit($count)
                ->get()
                ->each(function (User $user, int $i) use ($model, $count) {
                    $i < ($count / 2) && $model->isNot($user) && $model->follow($user);
                    $i >= ($count / 2) && $user->isNot($model) && $user->follow($model);
                });
            Artist::inRandomOrder()->limit(rand(1, 6))
                ->get()
                ->each(function (Artist $user) use ($model) {
                    $model->follow($user);
                });
        });
    }
}
