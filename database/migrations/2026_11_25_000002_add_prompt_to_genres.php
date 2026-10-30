<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Genres get a short description the style tagger listens for, filled in
     * for the genres its model doesn't know by name.
     */
    public function up(): void
    {
        Schema::table('genres', function (Blueprint $table) {
            $table->string('prompt', 300)->nullable();
        });

        $prompts = require database_path('data/genre-prompts.php');

        foreach ($prompts as $slug => $prompt) {
            DB::table('genres')
                ->where('slug', $slug)
                ->whereNull('prompt')
                ->update(['prompt' => $prompt]);
        }
    }

    public function down(): void
    {
        Schema::table('genres', function (Blueprint $table) {
            $table->dropColumn('prompt');
        });
    }
};
