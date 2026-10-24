<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fingerprints of uploaded audio, for spotting copies of other tracks.
     */
    public function up(): void
    {
        Schema::create('audio_fingerprints', function (Blueprint $table) {
            $table->id();
            $table->morphs('model');
            $table->string('hash', 64)->index();
            $table->float('duration')->default(0);
            $table->longText('codes')->nullable();
            $table->timestamps();

            $table->unique(['model_type', 'model_id']);
        });

        Schema::create('audio_fingerprint_keys', function (Blueprint $table) {
            $table->foreignId('audio_fingerprint_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('code');

            $table->index(['code', 'audio_fingerprint_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_fingerprint_keys');
        Schema::dropIfExists('audio_fingerprints');
    }
};
