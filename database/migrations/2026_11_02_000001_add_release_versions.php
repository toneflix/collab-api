<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A project can be released more than once. Each release is a version
     * with an optional label, and older ones can be retired.
     */
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->string('version')->nullable()->after('title');
            $table->timestamp('retired_at')->nullable()->after('draft');
        });
    }

    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->dropColumn(['version', 'retired_at']);
        });
    }
};
