<?php

namespace Database\Seeders;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Database\Seeder;

class ArtistSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Artist::factory()
            ->sequence(fn () => [
                'user_id' => User::where('data->fake', true)->inRandomOrder()->first()->id
            ])
            ->count(10)
            ->create();
    }
}
