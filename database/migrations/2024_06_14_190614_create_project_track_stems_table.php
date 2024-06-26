<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('project_track_stems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('stem_id')
                ->nullable()
                ->constrained('project_track_stems')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('track_id')
                ->constrained('tracks')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('src')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_track_stems');
    }
};