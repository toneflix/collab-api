<?php

namespace Database\Seeders;

use App\Models\Artist;
use App\Models\Project;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class MetricSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Add dummy views for releases
        $releases = Release::where('draft', false)
            ->limit(50)
            ->inRandomOrder()
            ->cursor();

        /** @var Release $model */
        foreach ($releases as $model) {
            User::inRandomOrder()
                ->where('data->fake', true)
                ->limit(rand(1, 10))
                ->get()
                ->each(fn (User $user) => $model->stream(0, $user));
        }

        // Add dummy stream for tracks
        $tracks = Track::whereHas('release', fn ($q) => $q->where('draft', false))
            ->limit(50)
            ->inRandomOrder()
            ->cursor();

        /** @var Track $model */
        foreach ($tracks as $model) {
            User::inRandomOrder()
                ->where('data->fake', true)
                ->limit(rand(1, 10))
                ->get()
                ->each(fn (User $user) => $model->stream(rand(50, 210), $user));
        }

        // Add dummy props for active projects
        $projects = Project::whereDoesntHave('release', fn ($q) => $q->where('draft', false))
            ->limit(50)
            ->inRandomOrder()
            ->cursor();

        /** @var Project $model */
        foreach ($projects as $model) {
            User::inRandomOrder()
                ->where('data->fake', true)
                ->limit(rand(1, 10))
                ->get()
                ->each(fn (User $user) => $model->giveVote($user));
        }

        // Add dummy followers for user
        $users = User::whereHas('projects')
            ->where('data->fake', true)
            ->limit(50)
            ->inRandomOrder()
            ->cursor();

        /** @var User $model */
        foreach ($users as $model) {
            User::inRandomOrder()
                ->where('data->fake', true)
                ->limit(rand(1, 10))
                ->get()
                ->each(fn (User $user) => $user->isNot($model) && $user->follow($model));
        }

        // Add dummy followers for artist
        $artists = Artist::whereHas('tracks')
            ->limit(50)
            ->inRandomOrder()
            ->cursor();

        /** @var Artist $model */
        foreach ($artists as $model) {
            User::inRandomOrder()
                ->where('data->fake', true)
                ->limit(rand(1, 10))
                ->get()
                ->each(fn (User $user) => $user->follow($model));
        }
    }
}