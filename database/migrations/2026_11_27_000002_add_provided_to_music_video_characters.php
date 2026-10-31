<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('music_video_characters', function (Blueprint $table) {
            // Uploaded by the creator with their sheet, so planning keeps it
            $table->boolean('provided')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('music_video_characters', function (Blueprint $table) {
            $table->dropColumn('provided');
        });
    }
};
