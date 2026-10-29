<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('track_artist', function (Blueprint $table) {
            $table->index(['artist_id', 'type']);
            $table->index(['track_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('track_artist', function (Blueprint $table) {
            $table->dropIndex(['artist_id', 'type']);
            $table->dropIndex(['track_id', 'type']);
        });
    }
};
