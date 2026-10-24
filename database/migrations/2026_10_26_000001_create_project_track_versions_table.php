<?php

use App\Enums\Feature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saved versions of a project track's arrangement, and removed stems kept
     * around so a version can bring them back.
     */
    public function up(): void
    {
        Schema::create('project_track_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')
                ->constrained('project_tracks')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->foreignId('restored_from_id')
                ->nullable()
                ->constrained('project_track_versions')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->string('action', 20);
            $table->string('summary')->nullable();
            $table->json('changes')->nullable();
            $table->json('snapshot');
            $table->jsonb('stem_ids');
            $table->timestamps();

            $table->index(['track_id', 'id']);
            $table->index('created_at');
        });

        Schema::table('project_track_stems', function (Blueprint $table) {
            $table->softDeletes();
        });

        DB::table('plans')->orderBy('id')->get()->each(function ($plan) {
            $features = json_decode((string) $plan->features, true) ?: [];

            if (! array_key_exists(Feature::HISTORY_DAYS->value, $features)) {
                $features[Feature::HISTORY_DAYS->value] = (float) $plan->price > 0 ? 365 : 30;
                DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode($features)]);
            }
        });
    }

    public function down(): void
    {
        DB::table('plans')->orderBy('id')->get()->each(function ($plan) {
            $features = json_decode((string) $plan->features, true) ?: [];
            unset($features[Feature::HISTORY_DAYS->value]);
            DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode($features)]);
        });

        DB::table('project_track_stems')->whereNotNull('deleted_at')->delete();

        Schema::table('project_track_stems', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::dropIfExists('project_track_versions');
    }
};
