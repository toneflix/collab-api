<?php

namespace App\Console\Commands;

use App\Services\Streams\StreamEarnings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SettleStreams extends Command
{
    protected $signature = 'earnings:settle-streams {--month= : The month to pay out, as YYYY-MM. Defaults to last month}';

    protected $description = 'Share a finished month\'s stream pool among creators by their weighted paid streams';

    public function handle(StreamEarnings $earnings): int
    {
        $month = $this->option('month')
            ? Carbon::createFromFormat('Y-m', (string) $this->option('month'))
            : now()->subMonthNoOverflow();

        $period = $earnings->settle($month->startOfMonth());

        $this->info("{$period->month->format('F Y')}: {$period->earners} creators earned {$period->paid_out} from a pool of {$period->pool}.");

        return self::SUCCESS;
    }
}
