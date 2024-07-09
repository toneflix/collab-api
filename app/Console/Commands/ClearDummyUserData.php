<?php

namespace App\Console\Commands;

use App\Models\Artist;
use App\Models\Release;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearDummyUserData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:clear-dummy-user-data';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clears all dummy data created from using the user migration.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // User
        //   Artist
        //   Release
        //   Track
        //   Transaction
        //   Coderflex\Laravisit\Models\Visit
        //   Overtrue\LaravelFollow\Followable
        //   ToneflixCode\ApprovableNotifications\Models\Notification
        //   Project
        //        ProjectTrack
        //        ProjectCollaborator
        //        SocialInteractions
        //        SocialInteractionSave

        User::whereJsonContains('data->fake', true)->each(function (User $user) {
            $user->delete();
        });

        Release::whereNull('user_id')->where('image', 'like', '%via.placeholder.com%')->each(function (Release $model) {
            $model->delete();
        });

        Artist::whereNull('user_id')->where('image', 'like', '%via.placeholder.com%')->each(function (Artist $model) {
            $model->delete();
        });

        collect([
            'Artist', 'User', 'Transaction', 'Release', 'Track', 'TrackArtist', 'Project', 'ProjectTrack', 'Followable',
            'ProjectCollaborator', 'SocialInteractions', 'SocialInteractionSave', 'ApprovableNotification', 'Laravisit',
            'Recommendation',
        ])->each(fn (string $t) => $this->resetAutoincrement($t));
    }

    protected function resetAutoincrement(string $table): void
    {
        $table = str($table)->snake()->lower()->when(! in_array(
            $table,
            ['TrackArtist']
        ), fn ($s) => $s->plural(2))->toString();

        $max = DB::table($table)->max('id') + 1;
        if (config('database.default') === 'mysql' || config('database.default') === 'mariadb') {
            DB::statement("ALTER TABLE $table AUTO_INCREMENT =  $max");
        } elseif (config('database.default') === 'pgsql') {
            DB::statement("ALTER TABLE $table AUTO_INCREMENT =  $max");
        }
    }
}
