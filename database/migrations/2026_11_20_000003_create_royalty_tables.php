<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Royalty program: admin invites to partners and the contracts they sign up on.
     */
    public function up(): void
    {
        Schema::create('royalty_invites', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('name');
            $table->decimal('rate', 5, 2);
            $table->unsignedSmallInteger('months')->default(0);
            $table->string('code', 40)->nullable();
            $table->text('note')->nullable();
            $table->string('token', 64)->unique();
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('invited_by_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('accepted_at')->nullable()->index();
            $table->timestampTz('cancelled_at')->nullable()->index();
            $table->timestampsTz();
        });

        Schema::create('royalty_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('royalty_invite_id')->nullable()->index()->constrained('royalty_invites')->nullOnDelete();
            $table->decimal('rate', 5, 2);
            $table->unsignedSmallInteger('months')->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable()->index();
            $table->timestampsTz();

            $table->index(['user_id', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('royalty_contracts');
        Schema::dropIfExists('royalty_invites');
    }
};
