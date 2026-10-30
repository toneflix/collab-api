<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_regions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->jsonb('countries');
            $table->jsonb('providers');
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 20, 8)->nullable();
            $table->decimal('rate_markup', 6, 2)->default(0);
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX payment_regions_countries_gin ON payment_regions USING gin (countries)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_regions');
    }
};
