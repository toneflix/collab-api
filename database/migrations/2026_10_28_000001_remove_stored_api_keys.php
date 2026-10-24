<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * API keys now come from `.env`, so drop the copies admins used to save.
     */
    public function up(): void
    {
        DB::table('configurations')->whereIn('key', [
            'paystack_secret_key',
            'paystack_public_key',
            'stripe_secret_key',
            'freesound_client_id',
            'freesound_client_secret',
            'reverb_app_id',
            'reverb_key',
            'reverb_secret',
            'reverb_host',
            'reverb_port',
            'reverb_scheme',
            'pusher_app_id',
            'pusher_key',
            'pusher_secret',
            'pusher_cluster',
            'ably_key',
            'firebase_credentials',
            'firebase_database_url',
            'firebase_api_key',
            'firebase_project_id',
            'firebase_app_id',
            'flutterwave_secret_key',
            'flutterwave_webhook_hash',
            'stripe_connect_secret_key',
            'stripe_webhook_secret',
        ])->delete();

        foreach (['configuration::build', 'realtime::settings', 'sounds::settings', 'payouts::settings'] as $key) {
            Cache::forget($key);
        }
    }

    public function down(): void
    {
        //
    }
};
