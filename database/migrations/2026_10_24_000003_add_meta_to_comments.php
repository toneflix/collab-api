<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extras on a message: a voice note, or the part of a track it's about.
     */
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('comment');
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
