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
        Schema::create('tracks', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title')->nullable();
            $table->foreignId('genre_id')->nullable()->constrained('genres')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('release_id')->nullable()->constrained('releases')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('image')->nullable();
            $table->string('audio')->nullable();
            $table->string('privacy')->nullable()->default('public')->comment('[private, public]');
            $table->integer('duration')->nullable()->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tracks');
    }
};
