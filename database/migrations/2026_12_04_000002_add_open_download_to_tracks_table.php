<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks anyone can download, opted in by creators with open downloads.
     */
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->boolean('open_download')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropColumn('open_download');
        });
    }
};
