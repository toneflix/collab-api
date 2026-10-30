<?php

namespace Database\Factories;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Release;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Valorin\Random\Random;

/**
 * @extends Factory<Release>
 */
class ReleaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $one = rand(1, 9);
        $sev = rand(0000001, 9999999);
        $ran = Random::string(3, false, true, false, false);
        $ran1 = Random::string(1, false, true, false, false);
        $lets = Random::string(3, false, true, false, false);

        $thr1 = rand(001, 999);
        $thr2 = rand(001, 999);
        $thr3 = rand(001, 999);

        return [
            'slug' => $this->faker->slug,
            'isrc' => "{$ran}{$one}{$ran1}{$sev}",
            'iswc' => "T-{$thr1}.{$thr2}.{$thr3}-$one",
            'title' => ucwords($this->faker->words(rand(2, 4), true)),
            'pline' => date('Y') . " $lets Music Limited",
            'draft' => false,
            'image' => $this->faker->imageUrl(480, 480, 'album', true),
            'privacy' => 'public',
            'genre_id' => Genre::inRandomOrder()->first()->id,
            'explicit' => $this->faker->boolean(),
            'composer' => $this->faker->name,
            'publisher' => "$lets Music Limited",
            'artist_id' => Artist::inRandomOrder()->first()?->id ?? Artist::factory()->create()->id,
            'description' => $this->faker->sentences(rand(2, 3), true),
            'release_date' => now(),
            'record_label' => "$lets Music",
            'contains_music' => $this->faker->boolean(),
            'downloadable' => true,
        ];
    }

    /**
     * Have 1 to 10 random users stream the release, for sample data.
     */
    public function streamed(): static
    {
        return $this->afterCreating(function (Release $model) {
            User::query()
                ->inRandomOrder()
                ->limit(rand(1, 10))
                ->get()
                ->each(fn(User $user) => $model->stream(rand(10, 320), $user));
        });
    }
}
