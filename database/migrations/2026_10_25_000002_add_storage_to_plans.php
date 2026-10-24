<?php

use App\Enums\Feature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give existing plans sound storage: 200 MB on free plans, 5 GB on paid ones.
     * Plans that already set it keep their value.
     */
    public function up(): void
    {
        DB::table('plans')->orderBy('id')->get()->each(function ($plan) {
            $features = json_decode((string) $plan->features, true) ?: [];

            if (! array_key_exists(Feature::MAX_STORAGE->value, $features)) {
                $features[Feature::MAX_STORAGE->value] = (float) $plan->price > 0 ? 5000 : 200;
                DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode($features)]);
            }
        });
    }

    public function down(): void
    {
        DB::table('plans')->orderBy('id')->get()->each(function ($plan) {
            $features = json_decode((string) $plan->features, true) ?: [];
            unset($features[Feature::MAX_STORAGE->value]);
            DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode($features)]);
        });
    }
};
