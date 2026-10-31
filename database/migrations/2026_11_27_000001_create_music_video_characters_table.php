<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('music_video_characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('music_video_id')->constrained()->cascadeOnDelete();
            // The planner's handle for the character, used by shots
            $table->string('key', 40);
            $table->string('name', 80);
            $table->text('description');
            $table->boolean('singer')->default(false);
            // Character sheet image on the uploads disk
            $table->string('sheet')->nullable();
            $table->timestampsTz();

            $table->unique(['music_video_id', 'key']);
        });

        Schema::table('music_video_shots', function (Blueprint $table) {
            // Keys of the characters seen in the shot
            $table->jsonb('characters')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('music_video_shots', function (Blueprint $table) {
            $table->dropColumn('characters');
        });

        Schema::dropIfExists('music_video_characters');
    }
};
