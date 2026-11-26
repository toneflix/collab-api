<?php

use App\Enums\Feature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Turn open downloads on for paid plans. Plans that already set it keep their value.
     */
    public function up(): void
    {
        DB::table('plans')
            ->orderBy('id')
            ->chunkById(100, function ($plans) {
                foreach ($plans as $plan) {
                    $features = json_decode((string) $plan->features, true) ?: [];

                    if (array_key_exists(Feature::OPEN_DOWNLOADS->value, $features)) {
                        continue;
                    }

                    $features[Feature::OPEN_DOWNLOADS->value] = (float) $plan->price > 0;

                    DB::table('plans')
                        ->where('id', $plan->id)
                        ->update(['features' => json_encode($features)]);
                }
            });
    }

    public function down(): void
    {
        DB::table('plans')
            ->orderBy('id')
            ->chunkById(100, function ($plans) {
                foreach ($plans as $plan) {
                    $features = json_decode((string) $plan->features, true) ?: [];
                    unset($features[Feature::OPEN_DOWNLOADS->value]);

                    DB::table('plans')
                        ->where('id', $plan->id)
                        ->update(['features' => json_encode($features)]);
                }
            });
    }
};
