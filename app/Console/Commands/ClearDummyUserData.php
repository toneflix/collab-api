<?php

namespace App\Console\Commands;

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

        collect([
            'Artist', 'User', 'Transaction', 'Release', "Track", 'Project', 'ProjectTrack', 'Followable',
            'ProjectCollaborator', 'SocialInteractions', 'SocialInteractionSave', 'ApprovableNotification'
        ])->each(fn (string $t) => $this->resetAutoincrement($t));
    }

    protected function resetAutoincrement(string $table): void
    {
        $table = str($table)->snake()->lower()->plural(2)->toString();

        $max = DB::table($table)->max('id') + 1;
        if (config('database.default') === 'mysql' || config('database.default') === 'mariadb') {
            DB::statement("ALTER TABLE $table AUTO_INCREMENT =  $max");
        } else if (config('database.default') === 'pgsql') {
            DB::statement("ALTER TABLE $table AUTO_INCREMENT =  $max");
        }
    }
}