<?php

use App\Enums\Feature;
use App\Models\Configuration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected array $settings = [
        ['stream_pool_share', 'Stream Pool (%)', 30, 'Part of each month\'s Premium revenue shared among creators by their paid streams.'],
        ['stream_min_seconds', 'Paid Stream Length (seconds)', 30, 'How long a signed-in listener must play a track for it to count as a paid stream.'],
        ['stream_daily_cap', 'Paid Streams per Listener per Day', 200, 'Most paid streams one listener can give in a day. A listener pays each track once a day.'],
        ['stream_weight_collaboration', 'Collaboration Stream Weight', 1.5, 'How much a paid stream of a collaboration counts.'],
        ['stream_weight_recorded', 'Recorded Stream Weight', 1, 'How much a paid stream of recorded music counts.'],
        ['stream_weight_agentic', 'Agentic Stream Weight', 0.5, 'How much a paid stream of agentic music counts. Unranked tracks count the same until ranked.'],
    ];

    public function up(): void
    {
        $decimals = ['stream_pool_share', 'stream_weight_collaboration', 'stream_weight_recorded', 'stream_weight_agentic'];

        foreach ($this->settings as [$key, $title, $value, $hint]) {
            if (! Configuration::where('key', $key)->exists()) {
                Configuration::insert([[
                    'key' => $key,
                    'title' => $title,
                    'value' => $value,
                    'type' => in_array($key, $decimals) ? 'decimal' : 'number',
                    'count' => null,
                    'max' => null,
                    'col' => 6,
                    'autogrow' => false,
                    'hint' => $hint,
                    'secret' => false,
                ]]);
            }
        }

        $this->grantPremium();

        Cache::forget('configuration::build');
    }

    public function down(): void
    {
        Configuration::whereIn('key', array_column($this->settings, 0))->delete();
        Cache::forget('configuration::build');
    }

    /**
     * Premium creators earn from streams.
     */
    protected function grantPremium(): void
    {
        $plan = DB::table('plans')->where('slug', 'premium')->first();

        if (! $plan) {
            return;
        }

        $features = json_decode((string) $plan->features, true) ?: [];
        $features[Feature::EARN_STREAMS->value] = true;

        DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode($features)]);
        Cache::forget('plans::default');
    }
};
