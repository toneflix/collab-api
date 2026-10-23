<?php

use App\Enums\Feature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Settings that plans replace.
     */
    protected array $oldSettings = [
        'premium_monthly_price', 'premium_yearly_price',
        'free_max_projects', 'premium_max_projects',
        'free_max_collaborators', 'premium_max_collaborators',
        'free_max_stem_size', 'premium_max_stem_size',
    ];

    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            // How long one payment lasts, e.g. 1 month or 1 year
            $table->string('interval')->default('month');
            $table->unsignedSmallInteger('interval_count')->default(1);
            // Feature key => value, see App\Enums\Feature
            $table->json('features')->nullable();
            // The plan everyone without a subscription is on
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->integer('sort')->default(0);
            $table->timestamps();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('transaction_id')
                ->constrained('plans')->cascadeOnUpdate()->nullOnDelete();
        });

        $this->seedPlans();
    }

    /**
     * Turn the old Premium settings into plans, so existing installs keep their prices and limits.
     */
    protected function seedPlans(): void
    {
        $old = DB::table('configurations')->whereIn('key', $this->oldSettings)->pluck('value', 'key');
        $setting = fn (string $key, int $fallback) => isset($old[$key]) ? (int) $old[$key] : $fallback;

        $free = collect(Feature::cases())->mapWithKeys(fn (Feature $f) => [$f->value => $f->fallback()])->all();
        $free[Feature::MAX_PROJECTS->value] = $setting('free_max_projects', 10);
        $free[Feature::MAX_COLLABORATORS->value] = $setting('free_max_collaborators', 5);
        $free[Feature::MAX_STEM_SIZE->value] = $setting('free_max_stem_size', 50);

        $premium = [
            ...$free,
            Feature::DOWNLOADS->value => true,
            Feature::BADGE->value => true,
            Feature::MAX_PROJECTS->value => $setting('premium_max_projects', 0),
            Feature::MAX_COLLABORATORS->value => $setting('premium_max_collaborators', 0),
            Feature::MAX_STEM_SIZE->value => $setting('premium_max_stem_size', 500),
        ];

        $now = now();
        $plans = [
            ['slug' => 'free', 'name' => 'Free', 'price' => 0, 'interval' => 'month', 'features' => $free, 'is_default' => true, 'sort' => 0,
                'description' => 'Stream, follow and collaborate.'],
            ['slug' => 'monthly', 'name' => 'Premium Monthly', 'price' => $setting('premium_monthly_price', 2000), 'interval' => 'month', 'features' => $premium, 'is_default' => false, 'sort' => 1,
                'description' => 'Everything in Premium, billed every month.'],
            ['slug' => 'yearly', 'name' => 'Premium Yearly', 'price' => $setting('premium_yearly_price', 20000), 'interval' => 'year', 'features' => $premium, 'is_default' => false, 'sort' => 2,
                'description' => 'Everything in Premium, billed once a year.'],
        ];

        foreach ($plans as $plan) {
            DB::table('plans')->insert([
                ...$plan,
                'features' => json_encode($plan['features']),
                'interval_count' => 1,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Link existing subscriptions to their plan
        foreach (DB::table('plans')->pluck('id', 'slug') as $slug => $id) {
            DB::table('subscriptions')->where('plan', $slug)->update(['plan_id' => $id]);
        }

        DB::table('configurations')->whereIn('key', $this->oldSettings)->delete();
        Cache::forget('configuration::build');
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
        });

        Schema::dropIfExists('plans');
    }
};
