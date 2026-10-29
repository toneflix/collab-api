<?php

use App\Models\Configuration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

return new class extends Migration
{
    protected array $settings = [
        [
            'key' => 'loyalty_rate',
            'title' => 'Invite Reward (%)',
            'value' => 10,
            'type' => 'number',
            'count' => null,
            'max' => null,
            'col' => 6,
            'autogrow' => false,
            'hint' => 'Part of the platform\'s share of each payment an invited person makes that goes to whoever invited them.',
            'secret' => false,
        ],
        [
            'key' => 'loyalty_months',
            'title' => 'Invite Reward Months',
            'value' => 12,
            'type' => 'number',
            'count' => null,
            'max' => null,
            'col' => 6,
            'autogrow' => false,
            'hint' => 'How long after sign-up an invited person\'s payments reward the inviter. 0 means no end.',
            'secret' => false,
        ],
    ];

    public function up(): void
    {
        foreach ($this->settings as $setting) {
            if (! Configuration::where('key', $setting['key'])->exists()) {
                Configuration::insert([$setting]);
            }
        }

        Cache::forget('configuration::build');
    }

    public function down(): void
    {
        Configuration::whereIn('key', array_column($this->settings, 'key'))->delete();
        Cache::forget('configuration::build');
    }
};
