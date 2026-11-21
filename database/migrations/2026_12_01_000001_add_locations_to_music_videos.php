<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('music_videos', function (Blueprint $table) {
            // How the storyboard is planned: a few locations, or the legacy story scenes
            $table->string('mode', 20)->default('locations');
            // The locations a locations storyboard is shot in, with the frames drawn for each
            $table->jsonb('locations')->nullable();
        });

        DB::table('music_videos')->update(['mode' => 'legacy']);

        Schema::table('music_video_shots', function (Blueprint $table) {
            $table->string('location', 40)->nullable();
            // Shots with the same frame start from one shared still
            $table->string('frame', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('music_video_shots', function (Blueprint $table) {
            $table->dropColumn(['location', 'frame']);
        });

        Schema::table('music_videos', function (Blueprint $table) {
            $table->dropColumn(['mode', 'locations']);
        });
    }
};
