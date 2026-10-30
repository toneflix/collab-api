<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('music_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            // See App\Models\MusicVideo statuses
            $table->string('status', 20)->default('planning');
            $table->string('ratio', 8)->default('16:9');
            $table->text('brief')->nullable();
            // Portrait the performance shots are drawn from, on the uploads disk
            $table->string('portrait')->nullable();
            // The look the planner chose: setting, palette, wardrobe
            $table->jsonb('style')->nullable();
            $table->decimal('duration', 8, 2)->default(0);
            // How the render was paid: credits, or minutes from the plan's monthly allowance
            $table->string('billing', 12)->nullable();
            $table->unsignedSmallInteger('minutes')->default(0);
            $table->string('file')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('billed_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'id']);
            $table->index(['track_id', 'id']);
            $table->index('status');
            $table->index(['user_id', 'billing', 'billed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('music_videos');
    }
};
