<?php

namespace App\Console\Commands;

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
        /** @var \App\Enums\PeriodDays $days */
        $days = config('settings.recommendation_expiry');
        $scope = fn (Builder $q) => $q->whereBetween('created_at', [now()->subDays($days->value), now()]);
        $streamScope = fn (Builder $q) => $q->where('data->duration', '>', 0)->where($scope);

        if (count($this->argument('user_ids'))) {
            /** @var \Illuminate\Support\LazyCollection<TKey,User> $users */
            $users = User::whereIn('id', $this->argument('user_ids'))
                ->whereDoesntHave('recommendations', $scope)
                ->where(function ($q) use ($streamScope, $scope) {
                    $q->whereHas('socialInteracts', $scope);
                    $q->orWhereHas('viewingHistory', $streamScope);
                    $q->orWhereHas('followings', $streamScope);
                })
                ->cursor();
        } else {
            $query = User::inRandomOrder()
                ->whereDoesntHave('recommendations', $scope)
                ->where(function ($q) use ($streamScope, $scope) {
                    $q->whereHas('followings', $scope);
                    $q->orWhereHas('socialInteracts', $scope);
                    $q->orWhereHas('viewingHistory', $streamScope);
                })
                ->limit(1000);

            /** @var \Illuminate\Support\LazyCollection<TKey,User> $users */
            $users = $query->cursor();
        }

        foreach ($users as $user) {
            $user->recommendations()->delete();
            new Recommendation($user, [Artist::class, User::class, Track::class]);
        }
    }
}
