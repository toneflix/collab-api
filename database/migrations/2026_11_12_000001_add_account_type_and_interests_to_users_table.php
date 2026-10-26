<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('account_type', 20)->nullable()->index();
            $table->jsonb('identities')->nullable();
            $table->timestampTz('interests_at')->nullable();
        });

        Schema::create('genre_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('genre_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();
            $table->unique(['user_id', 'genre_id']);
            $table->index('genre_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('genre_user');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['account_type', 'identities', 'interests_at']);
        });
    }
};
