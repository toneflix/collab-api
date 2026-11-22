<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('music_videos', function (Blueprint $table) {
            // How the song's synced lyrics are shown on the video, or null for none
            $table->string('lyrics', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('music_videos', function (Blueprint $table) {
            $table->dropColumn('lyrics');
        });
    }
};
