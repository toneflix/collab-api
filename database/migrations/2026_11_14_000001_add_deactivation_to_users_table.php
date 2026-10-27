<?php

use App\Models\Configuration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected array $setting = [
        'key' => 'account_deletion_days',
        'title' => 'Account Deletion Grace (days)',
        'value' => 30,
        'type' => 'number',
        'count' => null,
        'max' => null,
        'col' => 6,
        'autogrow' => false,
        'hint' => 'Days a deleted account waits, hidden, before it is erased. Signing in during this time cancels the deletion.',
        'secret' => false,
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Set while the owner has deactivated or asked to delete the account
            $table->timestampTz('deactivated_at')->nullable()->index();
            // When a deleted account is erased for good
            $table->timestampTz('deletes_at')->nullable()->index();
        });

        if (! Configuration::where('key', $this->setting['key'])->exists()) {
            Configuration::insert([$this->setting]);
        }

        Cache::forget('configuration::build');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['deactivated_at', 'deletes_at']);
        });

        Configuration::where('key', $this->setting['key'])->delete();
        Cache::forget('configuration::build');
    }
};
