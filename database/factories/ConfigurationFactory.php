<?php

namespace Database\Factories;

use App\Models\Configuration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Configuration>
 */
class ConfigurationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => $this->faker->slug,
            'title' => $this->faker->words(4, true),
            'value' => $this->faker->words(6),
            'type' => 'string',
            'autogrow' => false,
            'secret' => false,
            'count' => null,
            'max' => null,
            'col' => 12,
            'autogrow' => false,
            'hint' => $this->faker->sentence(10),
            'secret' => false,
        ];
    }
}
