<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_chats', function (Blueprint $table) {
            $table->id();
            // The admin who talks to the assistant; it acts as them
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120)->nullable();
            // See App\Models\AssistantChat: idle, thinking or failed
            $table->string('status', 20)->default('idle');
            $table->text('error')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assistant_chat_id')->constrained()->cascadeOnDelete();
            // admin, assistant, tool or action
            $table->string('role', 20);
            $table->text('content')->nullable();
            // A tool's name, input and result
            $table->jsonb('data')->nullable();
            $table->timestampsTz();

            $table->index(['assistant_chat_id', 'id']);
        });

        Schema::create('assistant_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assistant_chat_id')->constrained()->cascadeOnDelete();
            // The chat card the admin confirms the action on
            $table->foreignId('assistant_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tool', 60);
            $table->jsonb('input');
            $table->text('summary');
            // pending, done, failed, cancelled or expired
            $table->string('status', 20)->default('pending');
            $table->jsonb('result')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            $table->unique('assistant_message_id');
            $table->index(['assistant_chat_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_actions');
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_chats');
    }
};
