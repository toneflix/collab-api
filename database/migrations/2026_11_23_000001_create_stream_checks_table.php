<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listeners whose paid streams in a month mostly went to one creator, held for an admin to review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stream_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->date('month');
            $table->unsignedInteger('plays')->default(0);
            $table->decimal('share', 5, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->decimal('top_up', 14, 2)->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'owner_id', 'month']);
            $table->index(['status', 'month']);
            $table->index(['owner_id', 'month']);
        });

        Schema::table('track_plays', function (Blueprint $table) {
            $table->boolean('held')->default(false);
            $table->index(['owner_id', 'played_on', 'held']);
        });
    }

    public function down(): void
    {
        Schema::table('track_plays', function (Blueprint $table) {
            $table->dropIndex(['owner_id', 'played_on', 'held']);
            $table->dropColumn('held');
        });

        Schema::dropIfExists('stream_checks');
    }
};
