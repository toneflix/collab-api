<?php

namespace Database\Factories;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Track>
 */
class TrackFactory extends Factory
{
    use WithoutModelEvents;

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
            'image' => $this->faker->imageUrl(480, 480, 'album', true),
            'audio' => Track::whereNotNull('audio')->first()?->audio,
            'privacy' => 'public',
            'genre_id' => Genre::inRandomOrder()->first()->id,
            'description' => $this->faker->sentences(rand(2, 3), true),
        ];
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Track $model) {
            if ($model->release?->artist_id) {
                $model->artists()->attach($model->release?->artist_id);
            } else {
                $model->artists()->attach(Artist::inRandomOrder()->first()->id);
            }
            User::inRandomOrder()->limit(rand(1, 10))
                ->get()
                ->each(fn (User $user) => $model->stream(rand(10, 320), $user));
        });
    }
}