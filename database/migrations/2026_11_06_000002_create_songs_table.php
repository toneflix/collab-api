<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('songs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 150);
            $table->text('body')->nullable();
            $table->text('scratchpad')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('bpm')->nullable();
            $table->string('key', 10)->nullable();
            $table->foreignId('beat_sound_id')->nullable()->constrained('sounds')->nullOnDelete();
            $table->jsonb('settings')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('line_count')->default(0);
            $table->unsignedInteger('word_count')->default(0);
            $table->timestampTz('versioned_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('song_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('song_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 150);
            $table->text('body')->nullable();
            $table->string('kind', 20)->index();
            $table->string('name', 100)->nullable();
            $table->timestampTz('created_at')->nullable()->index();

            $table->index(['song_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('song_versions');
        Schema::dropIfExists('songs');
    }
};
