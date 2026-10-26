<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Renames the stored app name, unless an admin already picked a custom one.
     */
    public function up(): void
    {
        DB::table('configurations')
            ->where('key', 'app_name')
            ->whereIn('value', ['Laravel', 'Toneflix Collab', 'Toneflix'])
            ->update(['value' => 'Toneflix Music']);

        Cache::forget('configuration::build');
    }

    public function down(): void
    {
        //
    }
};
