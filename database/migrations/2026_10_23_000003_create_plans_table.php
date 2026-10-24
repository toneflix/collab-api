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
            // Paying for several periods at once, e.g. [{"cycles": 6, "discount": 10}]
            $table->json('billing_options')->nullable();
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
            Feature::MAX_STORAGE->value => 5000,
        ];

        $monthly = $setting('premium_monthly_price', 2000);
        $yearly = $setting('premium_yearly_price', 0);
        // Keep the old yearly price as the annual discount when there was one.
        $annualDiscount = $yearly > 0 && $monthly > 0 && $yearly < $monthly * 12
            ? round((1 - $yearly / ($monthly * 12)) * 100, 2)
            : 20;

        $now = now();
        $plans = [
            ['slug' => 'free', 'name' => 'Free', 'price' => 0, 'interval' => 'month', 'features' => $free, 'is_default' => true, 'sort' => 0,
                'billing_options' => [], 'description' => 'Stream, follow and collaborate.'],
            ['slug' => 'premium', 'name' => 'Premium', 'price' => $monthly, 'interval' => 'month', 'features' => $premium, 'is_default' => false, 'sort' => 1,
                'billing_options' => [
                    ['cycles' => 6, 'discount' => 10],
                    ['cycles' => 12, 'discount' => $annualDiscount],
                ],
                'description' => 'Downloads, a profile badge and no project limits.'],
        ];

        foreach ($plans as $plan) {
            DB::table('plans')->insert([
                ...$plan,
                'features' => json_encode($plan['features']),
                'billing_options' => json_encode($plan['billing_options']),
                'interval_count' => 1,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Link existing subscriptions to their plan. The old monthly and yearly
        // plans are now billing options of Premium.
        $ids = DB::table('plans')->pluck('id', 'slug');
        DB::table('subscriptions')->where('plan', 'free')->update(['plan_id' => $ids['free']]);
        DB::table('subscriptions')->whereIn('plan', ['premium', 'monthly', 'yearly'])->update(['plan_id' => $ids['premium']]);

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
