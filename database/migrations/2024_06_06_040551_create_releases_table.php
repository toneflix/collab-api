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
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('genre_id')->nullable()->constrained('genres')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('artist_id')->nullable()->constrained('artists')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnUpdate()->nullOnDelete();
            $table->string('image')->nullable();
            $table->string('privacy')->nullable()->default('public')->comment('[private, public]');
            $table->text('description')->nullable();
            $table->string('isrc')->nullable();
            $table->string('iswc')->nullable();
            $table->string('pline')->nullable();
            $table->string('record_label')->nullable();
            $table->boolean('draft')->default(true);
            $table->boolean('explicit')->default(false);
            $table->string('composer')->nullable();
            $table->string('publisher')->nullable();
            $table->boolean('contains_music')->default(true);
            $table->timestamp('release_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('releases');
    }
};
