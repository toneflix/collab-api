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
        Schema::create('track_artist', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->constrained('tracks')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('artist_id')->constrained('artists')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('type')->nullable()->default('primary')->comment('[primary, feature, producer, writer]');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('track_artist');
    }
};
