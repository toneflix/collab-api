<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reports become a moderation queue, and takedowns keep their history
     * and appeals.
     */
    public function up(): void
    {
        Schema::create('takedowns', function (Blueprint $table) {
            $table->id();
            $table->morphs('model');
            $table->foreignId('moderator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 40);
            $table->text('note')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('appeal')->nullable();
            $table->timestamp('appealed_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'appealed_at']);
        });

        Schema::table('safeties', function (Blueprint $table) {
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('takedown_id')->nullable()->constrained()->nullOnDelete();

            $table->index(['status', 'created_at']);
            $table->unique(['model_type', 'model_id', 'user_id']);
        });

        foreach (['releases', 'tracks', 'sounds', 'comments'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestamp('taken_down_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['releases', 'tracks', 'sounds', 'comments'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('taken_down_at');
            });
        }

        Schema::table('safeties', function (Blueprint $table) {
            $table->dropUnique(['model_type', 'model_id', 'user_id']);
            $table->dropIndex(['status', 'created_at']);
            $table->dropConstrainedForeignId('takedown_id');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['status', 'reviewed_at']);
        });

        Schema::dropIfExists('takedowns');
    }
};
