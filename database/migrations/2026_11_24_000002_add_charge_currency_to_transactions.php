<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->char('currency', 3)->nullable()->after('due');
            $table->decimal('charge_amount', 14, 2)->nullable()->after('currency');
            $table->decimal('exchange_rate', 20, 8)->nullable()->after('charge_amount');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['currency', 'charge_amount', 'exchange_rate']);
        });
    }
};
