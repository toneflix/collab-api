<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plan features admins give single accounts on top of their plan.
     */
    public function up(): void
    {
        Schema::create('feature_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 50)->index();
            $table->foreignId('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampsTz();

            $table->unique(['user_id', 'feature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_grants');
    }
};
