<?php

use App\Enums\Feature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // The month plan credits were last counted for
            $table->date('period')->nullable();
            // Plan credits used in that month
            $table->unsignedInteger('plan_used')->default(0);
            // Bought credits left; they don't expire
            $table->unsignedInteger('purchased')->default(0);
            $table->timestamps();
        });

        Schema::create('ai_credit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // purchase, spend, refund or grant
            $table->string('type', 20);
            // Positive when credits are added, negative when used
            $table->integer('credits');
            // How a spend was split, so a refund can put credits back where they came from
            $table->unsignedInteger('plan_credits')->default(0);
            $table->unsignedInteger('purchased_credits')->default(0);
            $table->string('description')->nullable();
            $table->nullableMorphs('source');
            $table->foreignId('refund_of')->nullable()->constrained('ai_credit_entries')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'id']);
        });

        $this->grantPremium();
    }

    /**
     * Give the Premium plan AI tools and 100 credits a month. A fresh install
     * already holds the off defaults here, since the plans migration fills
     * every Feature case, so those count as unset.
     */
    protected function grantPremium(): void
    {
        $plan = DB::table('plans')->where('slug', 'premium')->first();

        if (! $plan) {
            return;
        }

        $features = json_decode((string) $plan->features, true) ?: [];
        if (empty($features[Feature::AI_TOOLS->value])) {
            $features[Feature::AI_TOOLS->value] = true;
        }

        if (empty($features[Feature::AI_CREDITS->value])) {
            $features[Feature::AI_CREDITS->value] = 100;
        }

        DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode($features)]);
        Cache::forget('plans::default');
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credit_entries');
        Schema::dropIfExists('ai_wallets');
    }
};
