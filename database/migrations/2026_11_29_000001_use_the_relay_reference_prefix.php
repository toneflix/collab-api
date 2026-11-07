<?php

use App\Models\Configuration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

return new class extends Migration
{
    /**
     * Start payment references with the configured relay prefix (tfm_ by default),
     * unless an administrator already chose a prefix of their own.
     */
    public function up(): void
    {
        Configuration::query()
            ->where('key', 'reference_prefix')
            ->whereIn('value', ['TFC-', 'TRX-'])
            ->update(['value' => config('services.relay.reference_prefix', 'tfm_')]);

        Cache::forget('configuration::build');
    }

    public function down(): void {}
};
