<?php

use App\Models\Configuration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected array $settings = [
        [
            'key' => 'min_payout_amount',
            'title' => 'Minimum Withdrawal',
            'value' => 1000,
            'type' => 'number',
            'count' => null,
            'max' => null,
            'col' => 6,
            'autogrow' => false,
            'hint' => 'The smallest amount a creator can withdraw at once.',
            'secret' => false,
        ],
        [
            'key' => 'payout_hold_days',
            'title' => 'Earnings Clearing Days',
            'value' => 7,
            'type' => 'number',
            'count' => null,
            'max' => null,
            'col' => 6,
            'autogrow' => false,
            'hint' => 'How many days a payment waits before its earnings can be withdrawn, to cover refunds and chargebacks.',
            'secret' => false,
        ],
        [
            'key' => 'payout_auto_approve',
            'title' => 'Send Withdrawals Automatically',
            'value' => false,
            'type' => 'boolean',
            'count' => null,
            'max' => null,
            'col' => 6,
            'autogrow' => false,
            'hint' => 'Send withdrawals to Paystack as soon as creators ask, instead of waiting for an admin to approve them.',
            'secret' => false,
        ],
    ];

    public function up(): void
    {
        // The bank account a creator gets paid into
        Schema::create('payout_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('bank_code', 20);
            $table->string('bank_name');
            $table->string('account_number', 20);
            $table->string('account_name');
            $table->string('currency', 3)->default('NGN');
            // Paystack's transfer recipient for this account
            $table->string('recipient_code')->nullable();
            $table->timestamps();
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('NGN');
            // pending, processing, paid, failed, rejected or cancelled
            $table->string('status', 20)->default('pending');
            // The account it was sent to, kept as it was when requested
            $table->json('destination');
            $table->string('transfer_code')->nullable();
            $table->string('failure_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('gateway_response')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'updated_at']);
        });

        foreach ($this->settings as $row) {
            if (! Configuration::where('key', $row['key'])->exists()) {
                Configuration::insert([$row]);
            }
        }

        Cache::forget('configuration::build');
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('payout_accounts');

        Configuration::whereIn('key', array_column($this->settings, 'key'))->delete();
        Cache::forget('configuration::build');
    }
};
