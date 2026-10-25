<?php

use App\Models\Configuration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected array $setting = [
        'key' => 'review_releases',
        'title' => 'Review Releases',
        'value' => false,
        'type' => 'boolean',
        'count' => null,
        'max' => null,
        'col' => 6,
        'autogrow' => false,
        'hint' => 'Hold published releases in a review queue until an admin approves them.',
        'secret' => false,
    ];

    /**
     * Releases can wait for an admin's approval before they go live.
     */
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->string('review_status')->nullable()->after('retired_at')->index();
            $table->text('review_note')->nullable()->after('review_status');
            $table->timestamp('reviewed_at')->nullable()->after('review_note');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->json('retire_on_approval')->nullable()->after('reviewed_by');
        });

        if (! Configuration::where('key', $this->setting['key'])->exists()) {
            Configuration::insert([$this->setting]);
        }

        Cache::forget('configuration::build');
    }

    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['review_status', 'review_note', 'reviewed_at', 'retire_on_approval']);
        });

        Configuration::where('key', $this->setting['key'])->delete();
        Cache::forget('configuration::build');
    }
};
