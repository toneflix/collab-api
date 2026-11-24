<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('track_canvases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            // See App\Models\TrackCanvas statuses and sources
            $table->string('status', 16)->default('drawing');
            $table->string('source', 16)->default('ai');
            $table->text('brief')->nullable();
            $table->string('file')->nullable();
            $table->string('poster')->nullable();
            $table->decimal('duration', 6, 2)->default(0);
            $table->text('error')->nullable();
            $table->timestampsTz();

            $table->index(['track_id', 'status', 'id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('track_canvases');
    }
};
