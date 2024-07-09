<?php

namespace Database\Factories;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\ProjectTrack;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProjectTrack>
 */
class ProjectTrackFactory extends Factory
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
            'title' => ucwords($this->faker->words(rand(2, 4), true)),
            'privacy' => 'public',
            'preview' => ProjectTrack::whereNotNull('preview')->first()?->preview,
            'genre_id' => Genre::inRandomOrder()->first()->id,
            'artist_id' => Artist::inRandomOrder()->first()->id,
            'description' => $this->faker->sentences(rand(2, 3), true),
        ];
    }
}
