<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per listener, track and day. Replaces the laravisits table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('track_plays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->constrained('tracks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('played_on');
            $table->unsignedInteger('seconds')->default(0);
            $table->boolean('paid')->default(false);
            $table->timestampsTz();

            $table->unique(['track_id', 'user_id', 'played_on']);
            $table->index(['user_id', 'played_on', 'paid']);
            $table->index(['owner_id', 'played_on', 'paid']);
            $table->index(['played_on', 'paid']);
            $table->index(['track_id', 'created_at']);
        });

        Schema::dropIfExists('laravisits');
    }

    public function down(): void
    {
        Schema::dropIfExists('track_plays');

        Schema::create('laravisits', function (Blueprint $table) {
            $table->id();
            $table->morphs('visitable');
            $table->json('data');
            $table->timestamps();
        });
    }
};
