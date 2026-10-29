<?php

use App\Models\Configuration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

return new class extends Migration
{
    protected array $settings = [
        ['stream_check_plays', 'Stream Check Plays', 100, 'number', 'Paid streams one listener gives one creator in a month before they are checked.'],
        ['stream_check_share', 'Stream Check Share (%)', 80, 'decimal', 'Part of a listener\'s paid streams in a month going to one creator that holds them for review.'],
    ];

    public function up(): void
    {
        foreach ($this->settings as [$key, $title, $value, $type, $hint]) {
            if (! Configuration::where('key', $key)->exists()) {
                Configuration::insert([[
                    'key' => $key,
                    'title' => $title,
                    'value' => $value,
                    'type' => $type,
                    'count' => null,
                    'max' => null,
                    'col' => 6,
                    'autogrow' => false,
                    'hint' => $hint,
                    'secret' => false,
                ]]);
            }
        }

        Cache::forget('configuration::build');
    }

    public function down(): void
    {
        Configuration::whereIn('key', array_column($this->settings, 0))->delete();
        Cache::forget('configuration::build');
    }
};
