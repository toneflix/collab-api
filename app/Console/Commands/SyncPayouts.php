<?php

namespace App\Console\Commands;

use App\Enums\PayoutStatus;
use App\Models\Payout;
use App\Services\Payment\Payouts;
use Illuminate\Console\Command;

/**
 * Checks with the provider on payouts whose webhook hasn't arrived.
 */
class SyncPayouts extends Command
{
    protected $signature = 'payouts:sync {--older-than=10 : Only payouts that have been processing for this many minutes}';

    protected $description = 'Check on payouts that are still processing';

    public function handle(Payouts $payouts): int
    {
        $count = 0;

        Payout::status(PayoutStatus::PROCESSING)
            ->where('updated_at', '<=', now()->subMinutes((int) $this->option('older-than')))
            ->chunkById(100, function ($batch) use ($payouts, &$count) {
                foreach ($batch as $payout) {
                    try {
                        $payouts->sync($payout);
                        $count++;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        $this->info("Checked {$count} payouts.");

        return self::SUCCESS;
    }
}
