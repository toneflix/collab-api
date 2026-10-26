<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->jsonb('file_meta')->nullable();
        });

        Schema::table('project_track_stems', function (Blueprint $table) {
            $table->jsonb('file_meta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn('file_meta');
        });

        Schema::table('project_track_stems', function (Blueprint $table) {
            $table->dropColumn('file_meta');
        });
    }
};
