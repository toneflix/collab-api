<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sounds people can drop into a track: the shared library (user_id is
     * null), synced from Freesound or uploaded by admins, and each user's own
     * sounds. Freesound accounts people connect are kept for downloads.
     */
    public function up(): void
    {
        Schema::create('sounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('source', 20)->default('upload');
            $table->string('source_id')->nullable();
            $table->string('name');
            $table->string('category')->nullable();
            $table->json('tags')->nullable();
            $table->decimal('bpm', 6, 2)->nullable();
            $table->string('key', 20)->nullable();
            $table->float('duration')->default(0);
            $table->string('author')->nullable();
            $table->string('license')->nullable();
            $table->string('source_url')->nullable();
            $table->string('quality', 20)->nullable();
            $table->string('file');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->json('waveform')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->index(['source', 'source_id']);
        });

        Schema::create('freesound_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('username')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freesound_connections');
        Schema::dropIfExists('sounds');
    }
};
