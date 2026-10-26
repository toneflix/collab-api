<?php

use App\Models\Configuration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

return new class extends Migration
{
    protected array $setting = [
        'key' => 'studio_plugins',
        'title' => 'Studio Plugins',
        'value' => false,
        'type' => 'boolean',
        'count' => null,
        'max' => null,
        'col' => 6,
        'autogrow' => false,
        'hint' => 'Let people add Web Audio Module plugins from other sites to their stems.',
        'secret' => false,
    ];

    /**
     * Web Audio Module plugins in the studio, off until an admin turns them on.
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
