<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artist_voices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artist_id')->unique()->constrained('artists')->cascadeOnDelete();
            $table->string('description', 300)->nullable();
            $table->string('status', 20)->default('empty')->index();
            $table->string('source', 20)->nullable();
            $table->string('file')->nullable();
            $table->decimal('seconds', 6, 2)->nullable();
            $table->foreignId('ai_job_id')->nullable()->constrained('ai_jobs')->nullOnDelete();
            $table->string('persona_id')->nullable();
            $table->string('persona_kind', 20)->nullable();
            $table->string('check_status', 20)->nullable()->index();
            $table->string('check_task')->nullable();
            $table->text('check_phrase')->nullable();
            $table->text('failure')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_voices');
    }
};
