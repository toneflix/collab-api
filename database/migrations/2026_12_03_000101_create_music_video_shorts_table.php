<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('music_video_shorts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('music_video_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12)->default('cutting');
            $table->decimal('start', 8, 2);
            $table->decimal('end', 8, 2);
            // Where the vertical frame sits across the video, 0 (left) to 1 (right)
            $table->decimal('focus', 4, 3)->default(0.5);
            $table->string('file')->nullable();
            $table->string('error')->nullable();
            $table->timestampsTz();

            $table->index(['music_video_id', 'id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('music_video_shorts');
    }
};
