<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_track_id')->nullable()->constrained()->cascadeOnDelete();
            // music or stems, see App\Models\AiJob::TASKS
            $table->string('kind', 20);
            $table->string('status', 20)->default('queued');
            $table->json('input')->nullable();
            // What it made, e.g. the ids of the new stems
            $table->json('output')->nullable();
            $table->unsignedInteger('credits')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['project_track_id', 'id']);
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_jobs');
    }
};
