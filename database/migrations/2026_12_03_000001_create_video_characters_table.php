<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->text('description');
            // Character sheet image on the uploads disk
            $table->string('sheet');
            // The sheet is the creator's own image rather than one AI drew
            $table->boolean('uploaded')->default(false);
            $table->timestampsTz();

            $table->index(['user_id', 'id']);
        });

        Schema::table('music_video_characters', function (Blueprint $table) {
            // The saved character this one was cast from or saved as
            $table->foreignId('video_character_id')->nullable()->index()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('music_video_characters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('video_character_id');
        });

        Schema::dropIfExists('video_characters');
    }
};
