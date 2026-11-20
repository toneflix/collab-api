<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('music_video_shots', function (Blueprint $table) {
            $table->foreignId('take_id')->nullable()->constrained('music_video_takes')->nullOnDelete();
            // Seconds into the take the shot starts at
            $table->decimal('offset', 8, 2)->default(0);
            // zoom_in, zoom_out or pan: the shot plays its still instead of a take
            $table->string('motion', 12)->nullable();

            $table->index('take_id');
        });

        DB::table('music_video_shots')
            ->whereNotNull('clip')
            ->orderBy('id')
            ->chunkById(200, function ($shots) {
                foreach ($shots as $shot) {
                    $performance = $shot->kind === 'performance';
                    $take = DB::table('music_video_takes')
                        ->insertGetId([
                            'music_video_id' => $shot->music_video_id,
                            'shot_id' => $shot->id,
                            'source' => 'ai',
                            'file' => $shot->clip,
                            'length' => round($shot->end - $shot->start, 2),
                            'synced_from' => $performance ? $shot->start : null,
                            'synced_to' => $performance ? $shot->end : null,
                            'prompt' => $shot->prompt,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                    DB::table('music_video_shots')
                        ->where('id', $shot->id)
                        ->update(['take_id' => $take]);
                }
            });

        Schema::table('music_video_shots', function (Blueprint $table) {
            $table->dropColumn('clip');
        });
    }

    public function down(): void
    {
        Schema::table('music_video_shots', function (Blueprint $table) {
            $table->string('clip')->nullable();
        });

        DB::table('music_video_shots')
            ->whereNotNull('take_id')
            ->orderBy('id')
            ->chunkById(200, function ($shots) {
                foreach ($shots as $shot) {
                    $file = DB::table('music_video_takes')
                        ->where('id', $shot->take_id)
                        ->value('file');

                    DB::table('music_video_shots')
                        ->where('id', $shot->id)
                        ->update(['clip' => $file]);
                }
            });

        Schema::table('music_video_shots', function (Blueprint $table) {
            $table->dropForeign(['take_id']);
            $table->dropIndex(['take_id']);
            $table->dropColumn(['take_id', 'offset', 'motion']);
        });
    }
};
