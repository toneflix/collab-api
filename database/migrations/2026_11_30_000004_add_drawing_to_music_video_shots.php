<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('music_video_shots', function (Blueprint $table) {
            // A new still is being drawn for the shot
            $table->boolean('drawing')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('music_video_shots', function (Blueprint $table) {
            $table->dropColumn('drawing');
        });
    }
};
