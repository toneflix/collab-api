<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            // Who queued the run: an admin or a user of the agent account
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The account that owns what the run makes and pays its credits
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->text('prompt');
            $table->jsonb('options')->nullable();
            $table->string('status', 20)->default('queued');
            $table->jsonb('plan')->nullable();
            $table->unsignedInteger('credits')->default(0);
            $table->text('error')->nullable();
            $table->text('failure')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'id']);
            $table->index(['owner_id', 'id']);
        });

        Schema::create('agent_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            // Unique within the run, like "music:t3"; other steps wait on it by this key
            $table->string('key', 60);
            // See App\Models\AgentStep::ACTIONS
            $table->string('kind', 20);
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('position')->default(0);
            $table->jsonb('depends_on')->nullable();
            $table->jsonb('input')->nullable();
            $table->jsonb('output')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique(['agent_run_id', 'key']);
            $table->index(['agent_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_steps');
        Schema::dropIfExists('agent_runs');
    }
};
