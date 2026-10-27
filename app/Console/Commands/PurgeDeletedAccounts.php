<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class PurgeDeletedAccounts extends Command
{
    protected $signature = 'accounts:purge';

    protected $description = 'Erase deleted accounts whose grace period has ended, with their releases and artists';

    public function handle(): int
    {
        $count = 0;

        User::query()
            ->withoutGlobalScopes()
            ->whereNotNull('deactivated_at')
            ->whereNotNull('deletes_at')
            ->where('deletes_at', '<=', now())
            ->chunkById(100, function (Collection $users) use (&$count) {
                foreach ($users as $user) {
                    $user->data['eraseOnDelete'] = true;
                    $user->delete();
                    $count++;
                }
            });

        $this->info("Erased {$count} deleted account(s).");

        return self::SUCCESS;
    }
}
