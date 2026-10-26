<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->string('slug', 180)->nullable()->unique();
            $table->string('image')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->boolean('allow_forks')->default(false);
            $table->boolean('allow_projects')->default(false);
            $table->foreignId('forked_from_id')->nullable()->index()->constrained('songs')->nullOnDelete();
            $table->timestampTz('taken_down_at')->nullable()->index();

            $table->index(['published_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->dropIndex(['published_at', 'id']);
            $table->dropConstrainedForeignId('forked_from_id');
            $table->dropColumn(['slug', 'image', 'published_at', 'allow_forks', 'allow_projects', 'taken_down_at']);
        });
    }
};
