<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('words', function (Blueprint $table) {
            $table->id();
            $table->string('word', 80)->unique();
            $table->string('phones');
            $table->unsignedTinyInteger('syllables')->index();
            $table->unsignedTinyInteger('rank')->default(99)->index();
            $table->string('stress', 20)->nullable();
            $table->string('rhyme_key', 60)->nullable()->index();
            $table->string('vowel_key', 60)->nullable()->index();
            $table->string('tail2', 20)->nullable()->index();
            $table->string('tail3', 30)->nullable()->index();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX words_word_prefix_index ON words (word text_pattern_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('words');
    }
};
