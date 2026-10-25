<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Released tracks and studio tracks carry the tempo and key detected in
     * their audio, or set by their creator.
     */
    public function up(): void
    {
        foreach (['tracks', 'project_tracks'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->decimal('bpm', 5, 1)->nullable()->index();
                $table->string('musical_key', 12)->nullable()->index();
                $table->string('tags_source', 12)->nullable();
                $table->timestamp('tagged_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['tracks', 'project_tracks'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropIndex(['bpm']);
                $table->dropIndex(['musical_key']);
                $table->dropColumn(['bpm', 'musical_key', 'tags_source', 'tagged_at']);
            });
        }
    }
};
