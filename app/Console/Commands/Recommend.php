<?php

namespace App\Console\Commands;

use App\Enums\PeriodDays;
use App\Models\Artist;
use App\Models\Track;
use App\Models\User;
use App\Services\Recommendation;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class Recommend extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:recommend
                                {user_ids?* : Space separated ID(s) of the user(s) to make recommendations for}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recommend resources to the User model based on activity history';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        /** @var PeriodDays $days */
        $days = config('settings.recommendation_expiry');
        $fresh = fn (Builder $q) => $q->where('created_at', '>=', now()->subDays($days->value));

        // Only users with some activity to learn from, whose recommendations have expired.
        $users = User::query()
            ->when(
                count($this->argument('user_ids')),
                fn (Builder $q) => $q->whereIn('id', $this->argument('user_ids')),
                fn (Builder $q) => $q->whereDoesntHave('recommendations', $fresh)->limit(1000)
            )
            ->where(function (Builder $q) {
                $q->has('followings')
                    ->orHas('socialInteracts')
                    ->orHas('viewingHistory');
            })
            ->cursor();

        $count = 0;

        foreach ($users as $user) {
            $made = (new Recommendation($user, [Artist::class, User::class, Track::class]))->generate();
            $this->line("User {$user->id}: {$made} recommendations", verbosity: 'v');
            $count++;
        }

        $this->info("Refreshed recommendations for {$count} user(s).");
    }
}
