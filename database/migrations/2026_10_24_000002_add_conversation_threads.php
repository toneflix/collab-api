<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every comment, reply or not, records the conversation it belongs to
     * (the project track, track, release or project), so a conversation's
     * messages and the conversations a user is in can be read in one query.
     * `conversation_reads` remembers when each user last read each one.
     */
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->string('root_type')->nullable()->after('commentable_id');
            $table->unsignedBigInteger('root_id')->nullable()->after('root_type');
            $table->index(['root_type', 'root_id', 'id']);
        });

        Schema::create('conversation_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('root_type');
            $table->unsignedBigInteger('root_id');
            $table->timestamp('read_at');
            $table->unique(['user_id', 'root_type', 'root_id']);
        });

        // Top-level comments belong to what they're on.
        DB::table('comments')->where('commentable_type', '!=', 'App\\Models\\Comment')->update([
            'root_type' => DB::raw('commentable_type'),
            'root_id' => DB::raw('commentable_id'),
        ]);

        // Replies take their parent's conversation, one level of nesting at a time.
        for ($depth = 0; $depth < 10; $depth++) {
            $pending = DB::table('comments as c')
                ->join('comments as p', 'p.id', '=', 'c.commentable_id')
                ->where('c.commentable_type', 'App\\Models\\Comment')
                ->whereNull('c.root_type')
                ->whereNotNull('p.root_type')
                ->get(['c.id', 'p.root_type', 'p.root_id']);

            if ($pending->isEmpty()) {
                break;
            }

            foreach ($pending as $row) {
                DB::table('comments')->where('id', $row->id)->update([
                    'root_type' => $row->root_type,
                    'root_id' => $row->root_id,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_reads');

        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndex(['root_type', 'root_id', 'id']);
            $table->dropColumn(['root_type', 'root_id']);
        });
    }
};
