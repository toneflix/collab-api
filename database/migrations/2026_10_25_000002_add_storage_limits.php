<?php

use App\Models\Configuration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

return new class extends Migration
{
    /**
     * How much space each plan has for its own sounds.
     */
    protected array $rows = [
        [
            'key' => 'free_max_storage',
            'title' => 'Free: Sound Storage (MB)',
            'value' => 200,
            'type' => 'number',
            'count' => null,
            'max' => null,
            'col' => 6,
            'autogrow' => false,
            'hint' => 'How much space a free account has for its own sounds, in MB. 0 means no limit.',
            'secret' => false,
        ],
        [
            'key' => 'premium_max_storage',
            'title' => 'Premium: Sound Storage (MB)',
            'value' => 5000,
            'type' => 'number',
            'count' => null,
            'max' => null,
            'col' => 6,
            'autogrow' => false,
            'hint' => 'How much space a Premium account has for its own sounds, in MB. 0 means no limit.',
            'secret' => false,
        ],
    ];

    public function up(): void
    {
        foreach ($this->rows as $row) {
            if (! Configuration::where('key', $row['key'])->exists()) {
                Configuration::insert([$row]);
            }
        }

        Cache::forget('configuration::build');
    }

    public function down(): void
    {
        Configuration::whereIn('key', array_column($this->rows, 'key'))->delete();
        Cache::forget('configuration::build');
    }
};
