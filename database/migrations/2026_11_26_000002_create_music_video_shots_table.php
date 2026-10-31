<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('music_video_shots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('music_video_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            // Seconds into the song
            $table->decimal('start', 8, 2);
            $table->decimal('end', 8, 2);
            // performance (lip sync) or broll
            $table->string('kind', 12);
            $table->text('prompt');
            // Still and rendered clip on the uploads disk
            $table->string('keyframe')->nullable();
            $table->string('clip')->nullable();
            $table->timestampsTz();

            $table->unique(['music_video_id', 'position']);
            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('music_video_shots');
    }
};
