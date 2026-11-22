<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('music_videos', function (Blueprint $table) {
            // Which models render the video: standard or advanced
            $table->string('quality', 20)->default('standard');
        });
    }

    public function down(): void
    {
        Schema::table('music_videos', function (Blueprint $table) {
            $table->dropColumn('quality');
        });
    }
};
