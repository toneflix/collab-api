<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payout accounts and payouts can go through Paystack, Flutterwave or
     * Stripe Connect, to banks or mobile money, in the creator's own currency.
     */
    public function up(): void
    {
        Schema::table('payout_accounts', function (Blueprint $table) {
            $table->string('provider', 20)->default('paystack')->after('user_id');
            $table->string('country', 2)->nullable()->after('provider');
            // bank, mobile_money or hosted (the provider collects the details)
            $table->string('method', 20)->default('bank')->after('country');
            // active, or pending while the creator finishes the provider's onboarding
            $table->string('status', 20)->default('active')->after('recipient_code');
            $table->string('bank_code', 20)->nullable()->change();
            $table->string('bank_name')->nullable()->change();
            $table->string('account_number', 30)->nullable()->change();
        });

        Schema::table('payouts', function (Blueprint $table) {
            $table->string('provider', 20)->default('paystack')->after('currency');
            // What the creator receives, which can be in another currency
            $table->decimal('payout_amount', 14, 2)->nullable()->after('provider');
            $table->string('payout_currency', 3)->nullable()->after('payout_amount');
            $table->decimal('exchange_rate', 18, 8)->nullable()->after('payout_currency');
        });

        DB::table('payout_accounts')->whereNull('country')->update(['country' => 'NG']);
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropColumn(['provider', 'payout_amount', 'payout_currency', 'exchange_rate']);
        });

        Schema::table('payout_accounts', function (Blueprint $table) {
            $table->dropColumn(['provider', 'country', 'method', 'status']);
        });
    }
};
