<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $tables = ['projects', 'releases', 'tracks'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $tn) {
            if (!Schema::hasIndex($tn, "{$tn}_description_fulltext")) {
                Schema::table($tn, function (Blueprint $table) {
                    $table->text('description')->change()->nullable()->fulltext();
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tables as $tn) {
            if (Schema::hasIndex($tn, "{$tn}_description_fulltext")) {
                Schema::table($tn, function (Blueprint $table) use ($tn) {
                    $table->dropIndex("{$tn}_description_fulltext");
                });
            }
        }
    }
};