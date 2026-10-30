<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Released tracks and studio tracks carry the moods heard in their audio
     * or set by their creator, the genre their audio sounds like, and the
     * scores both came from.
     */
    public function up(): void
    {
        foreach (['tracks', 'project_tracks'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->jsonb('moods')->nullable();
                $table->string('moods_source', 12)->nullable();
                $table->foreignId('suggested_genre_id')->nullable()->index()->constrained('genres')->nullOnDelete();
                $table->jsonb('style_scores')->nullable();
                $table->timestampTz('styled_at')->nullable();
            });

            if (DB::getDriverName() === 'pgsql') {
                DB::statement("CREATE INDEX {$name}_moods_gin ON {$name} USING gin (moods)");
            }
        }
    }

    public function down(): void
    {
        foreach (['tracks', 'project_tracks'] as $name) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("DROP INDEX IF EXISTS {$name}_moods_gin");
            }

            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('suggested_genre_id');
                $table->dropColumn(['moods', 'moods_source', 'style_scores', 'styled_at']);
            });
        }
    }
};
