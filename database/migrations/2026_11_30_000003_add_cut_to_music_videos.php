<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('music_videos', function (Blueprint $table) {
            // The edit list the finished file was stitched from
            $table->jsonb('cut')->nullable();
            // When the shots were changed after the last stitch
            $table->timestampTz('edited_at')->nullable();
            // Plan video time is counted from the render jobs, in seconds
            $table->dropIndex(['user_id', 'billing', 'billed_at']);
            $table->dropColumn('minutes');
        });
    }

    public function down(): void
    {
        Schema::table('music_videos', function (Blueprint $table) {
            $table->dropColumn(['cut', 'edited_at']);
            $table->unsignedSmallInteger('minutes')->default(0);

            $table->index(['user_id', 'billing', 'billed_at']);
        });
    }
};
