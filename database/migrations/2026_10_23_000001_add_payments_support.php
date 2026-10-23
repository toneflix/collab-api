<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // What a listener pays to unlock a release. 0 means it's free.
        Schema::table('releases', function (Blueprint $table) {
            $table->decimal('price', 12, 2)->default(0)->after('privacy');
        });

        // What a listener pays to download a track's stems. 0 means they are not for sale.
        Schema::table('tracks', function (Blueprint $table) {
            $table->decimal('stems_price', 12, 2)->default(0)->after('privacy');
        });

        // Links a released track to the project track it came from, which is where its stems live.
        // The models already use it, but no earlier migration created it.
        if (! Schema::hasColumn('tracks', 'project_track_id')) {
            Schema::table('tracks', function (Blueprint $table) {
                $table->foreignId('project_track_id')->nullable()->after('release_id')
                    ->constrained('project_tracks')->cascadeOnUpdate()->nullOnDelete();
            });
        }

        Schema::table('transactions', function (Blueprint $table) {
            // premium, release, stems or tip
            $table->string('purpose')->nullable()->after('method');
            // The creator who earns from this payment, if anyone
            $table->foreignId('recipient_id')->nullable()->after('user_id')
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->decimal('recipient_share', 12, 2)->default(0)->after('fees');
            $table->json('meta')->nullable()->after('webhook');
            $table->timestamp('paid_at')->nullable()->after('meta');
            $table->index('reference');
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->cascadeOnUpdate()->nullOnDelete();
            $table->string('plan');
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['reference']);
            $table->dropConstrainedForeignId('recipient_id');
            $table->dropColumn(['purpose', 'recipient_share', 'meta', 'paid_at']);
        });

        Schema::table('tracks', fn (Blueprint $table) => $table->dropColumn('stems_price'));
        Schema::table('releases', fn (Blueprint $table) => $table->dropColumn('price'));
    }
};
