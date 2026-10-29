<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How each released track was made, and who decided it.
     */
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->string('origin', 20)->nullable()->index();
            $table->string('origin_by', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropColumn(['origin', 'origin_by']);
        });
    }
};
