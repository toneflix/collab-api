<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lyrics', function (Blueprint $table) {
            $table->id();
            // A released track, or a project track in the studio
            $table->morphs('lyricable');
            // [{start, end, text}], times in seconds or null when unsynced
            $table->json('lines');
            $table->text('text')->nullable();
            $table->string('language', 20)->nullable();
            // transcribed or written
            $table->string('source', 20)->default('written');
            $table->boolean('explicit')->default(false);
            // Words the content check found: {explicit: [], review: []}
            $table->json('flagged')->nullable();
            $table->foreignId('ai_job_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['lyricable_type', 'lyricable_id']);
            $table->fullText('text');
        });

        Schema::table('tracks', function (Blueprint $table) {
            $table->boolean('explicit')->default(false)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropColumn('explicit');
        });

        Schema::dropIfExists('lyrics');
    }
};
