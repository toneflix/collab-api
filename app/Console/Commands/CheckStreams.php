<?php

namespace App\Console\Commands;

use App\Services\Streams\StreamChecks;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CheckStreams extends Command
{
    protected $signature = 'earnings:check-streams {--month= : The month to check, as YYYY-MM. Defaults to this month}';

    protected $description = 'Hold paid streams from listeners who mostly play one creator, for an admin to review';

    public function handle(StreamChecks $checks): int
    {
        $month = $this->option('month')
            ? Carbon::createFromFormat('!Y-m', (string) $this->option('month'))
            : now();

        $opened = $checks->scan($month->startOfMonth());

        $this->info("{$month->format('F Y')}: {$opened} new stream checks.");

        return self::SUCCESS;
    }
}
