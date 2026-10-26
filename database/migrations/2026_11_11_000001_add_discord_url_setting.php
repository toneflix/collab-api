<?php

use App\Models\Configuration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

return new class extends Migration
{
    protected array $setting = [
        'key' => 'discord_url',
        'title' => 'Discord Link',
        'value' => 'https://discord.gg/u7fQdqQt8c',
        'type' => 'url',
        'count' => null,
        'max' => null,
        'col' => 6,
        'autogrow' => false,
        'hint' => 'Community invite shown on the Help page.',
        'secret' => false,
    ];

    /**
     * The community Discord invite, seeded with the link the app used before.
     */
    public function up(): void
    {
        if (! Configuration::where('key', $this->setting['key'])->exists()) {
            Configuration::insert([$this->setting]);
        }

        Cache::forget('configuration::build');
    }

    public function down(): void
    {
        Configuration::where('key', $this->setting['key'])->delete();
        Cache::forget('configuration::build');
    }
};
