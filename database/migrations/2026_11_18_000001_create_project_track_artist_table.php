<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_track_artist', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_track_id')->constrained('project_tracks')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('artist_id')->constrained('artists')->cascadeOnUpdate()->cascadeOnDelete();
            $table->timestampsTz();
            $table->unique(['project_track_id', 'artist_id']);
            $table->index('artist_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_track_artist');
    }
};
