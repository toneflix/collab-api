<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_reviews', function (Blueprint $table) {
            $table->id();
            $table->morphs('subject');
            $table->string('mode', 10);
            $table->string('status', 20)->default('queued')->index();
            $table->string('decision', 10)->nullable();
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->text('summary')->nullable();
            $table->text('note')->nullable();
            $table->text('error')->nullable();
            $table->string('model')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_reviews');
    }
};
