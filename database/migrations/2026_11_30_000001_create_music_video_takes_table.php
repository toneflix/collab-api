<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('music_video_takes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('music_video_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shot_id')->nullable()->constrained('music_video_shots')->nullOnDelete();
            // ai (rendered from the shot's still) or upload (the creator's own clip)
            $table->string('source', 12)->default('ai');
            $table->string('file');
            $table->decimal('length', 8, 2);
            // Song seconds a lip-synced take was made for, null for b-roll
            $table->decimal('synced_from', 8, 2)->nullable();
            $table->decimal('synced_to', 8, 2)->nullable();
            $table->text('prompt')->nullable();
            $table->timestampsTz();

            $table->index(['music_video_id', 'id']);
            $table->index(['shot_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('music_video_takes');
    }
};
