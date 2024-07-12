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
        Schema::create('project_tracks', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title')->nullable();
            $table->string('preview')->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('artist_id')->nullable()->constrained('artists')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('genre_id')->nullable()->constrained('genres')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('privacy')->nullable()->default('public')->comment('[private, public]');
            $table->text('description')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_tracks');
    }
};
