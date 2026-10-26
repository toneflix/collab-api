<?php

use App\Models\Configuration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected array $setting = [
        'key' => 'track_downloads',
        'title' => 'Track Downloads',
        'value' => true,
        'type' => 'boolean',
        'count' => null,
        'max' => null,
        'col' => 6,
        'autogrow' => false,
        'hint' => 'Let listeners whose plan includes downloads save tracks the creator allowed.',
        'secret' => false,
    ];

    /**
     * Creators choose whether each release can be downloaded, and admins can turn downloads off.
     * Releases published before this keep the downloads they had.
     */
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->boolean('downloadable')->default(false)->index();
        });

        DB::table('releases')->update(['downloadable' => true]);

        if (! Configuration::where('key', $this->setting['key'])->exists()) {
            Configuration::insert([$this->setting]);
        }

        Cache::forget('configuration::build');
    }

    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->dropColumn('downloadable');
        });

        Configuration::where('key', $this->setting['key'])->delete();
        Cache::forget('configuration::build');
    }
};
