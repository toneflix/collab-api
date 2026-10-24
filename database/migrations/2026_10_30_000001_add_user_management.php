<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable();
            // Null while suspended means the account is banned until an admin lifts it
            $table->timestamp('suspended_until')->nullable();
            $table->string('suspension_reason')->nullable();
        });

        Schema::create('admin_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 40);
            $table->string('reason')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_actions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'suspended_until', 'suspension_reason']);
        });
    }
};
