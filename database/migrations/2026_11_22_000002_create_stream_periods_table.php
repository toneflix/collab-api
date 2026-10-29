<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each settled month of stream earnings: the pool and what one weighted play paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stream_periods', function (Blueprint $table) {
            $table->id();
            $table->date('month')->unique();
            $table->string('status', 20)->default('settling')->index();
            $table->decimal('revenue', 14, 2)->default(0);
            $table->decimal('share', 5, 2)->default(0);
            $table->decimal('pool', 14, 2)->default(0);
            $table->unsignedInteger('plays')->default(0);
            $table->decimal('weighted', 14, 2)->default(0);
            $table->decimal('rate', 16, 6)->default(0);
            $table->unsignedInteger('earners')->default(0);
            $table->decimal('paid_out', 14, 2)->default(0);
            $table->jsonb('weights')->nullable();
            $table->timestampTz('settled_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stream_periods');
    }
};
