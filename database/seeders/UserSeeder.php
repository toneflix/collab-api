<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::factory()
            ->has(
                Release::factory()
                    ->has(Track::factory()->count(rand(3, 5)), 'tracks')
                    ->count(rand(1, 3)),
                'releases'
            )
            ->has(
                Project::factory()
                    ->has(ProjectTrack::factory()->count(rand(3, 5)), 'tracks')
                    ->count(rand(1, 3)),
                'projects'
            )
            ->count(50)
            ->create();
    }
}