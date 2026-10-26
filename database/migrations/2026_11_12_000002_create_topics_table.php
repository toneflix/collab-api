<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('topics', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('title', 40);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('topic_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();
            $table->unique(['user_id', 'topic_id']);
            $table->index('topic_id');
        });

        Schema::create('release_topic', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();
            $table->unique(['release_id', 'topic_id']);
            $table->index('topic_id');
        });

        $now = now();
        $topics = require database_path('data/topics.php');

        $rows = collect($topics)
            ->map(fn(string $title, string $slug) => [
                'slug' => $slug,
                'title' => $title,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        DB::table('topics')->insertOrIgnore($rows);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('release_topic');
        Schema::dropIfExists('topic_user');
        Schema::dropIfExists('topics');
    }
};
