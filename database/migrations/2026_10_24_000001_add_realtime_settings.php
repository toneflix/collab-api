<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Realtime settings live in `configurations`. A Firebase service account
     * and encrypted secrets don't fit in 255 characters, so values become text.
     */
    public function up(): void
    {
        Schema::table('configurations', function (Blueprint $table) {
            $table->text('value')->nullable()->change();
        });

        DB::table('configurations')->insertOrIgnore([
            'key' => 'realtime_driver',
            'title' => 'Realtime Driver',
            'type' => 'string',
            'value' => 'none',
            'col' => 12,
            'hint' => 'none, reverb, pusher, ably or firebase',
            'autogrow' => false,
            'secret' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('configurations')->where('key', 'like', 'realtime_%')
            ->orWhere('key', 'like', 'reverb_%')
            ->orWhere('key', 'like', 'pusher_%')
            ->orWhere('key', 'like', 'ably_%')
            ->orWhere('key', 'like', 'firebase_%')
            ->delete();
    }
};
