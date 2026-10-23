<?php

use App\Models\Configuration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

return new class extends Migration
{
    protected array $rows = [
        [
            'key' => 'premium_monthly_price',
            'title' => 'Premium Monthly Price',
            'value' => 2000,
            'type' => 'number',
            'count' => null,
            'max' => null,
            'col' => 6,
            'autogrow' => false,
            'hint' => 'What listeners pay for one month of Premium.',
            'secret' => false,
        ],
        [
            'key' => 'premium_yearly_price',
            'title' => 'Premium Yearly Price',
            'value' => 20000,
            'type' => 'number',
            'count' => null,
            'max' => null,
            'col' => 6,
            'autogrow' => false,
            'hint' => 'What listeners pay for one year of Premium.',
            'secret' => false,
        ],
        [
            'key' => 'creator_share',
            'title' => 'Creator Share (%)',
            'value' => 85,
            'type' => 'number',
            'count' => null,
            'max' => null,
            'col' => 6,
            'autogrow' => false,
            'hint' => 'Percentage of each release, stems or tip payment that goes to the creator.',
            'secret' => false,
        ],
        [
            'key' => 'min_tip_amount',
            'title' => 'Minimum Tip',
            'value' => 100,
            'type' => 'number',
            'count' => null,
            'max' => null,
            'col' => 6,
            'autogrow' => false,
            'hint' => 'The smallest tip a listener can send.',
            'secret' => false,
        ],
        [
            'key' => 'free_max_projects',
            'title' => 'Free: Max Projects',
            'value' => 10,
            'type' => 'number',
            'count' => null,
            'max' => null,
            'col' => 6,
            'autogrow' => false,
            'hint' => 'How many projects a free account can create. 0 means no limit.',
            'secret' => false,
        ],
        [
            'key' => 'premium_max_projects',
            'title' => 'Premium: Max Projects',
            'value' => 0,
            'type' => 'number',
            'count' => null,
            'max' => null,
            'col' => 6,
            'autogrow' => false,
            'hint' => 'How many projects a Premium account can create. 0 means no limit.',
            'secret' => false,
        ],
        [
            'key' => 'free_max_collaborators',
            'title' => 'Free: Max Collaborators per Project',
            'value' => 5,
            'type' => 'number',
            'count' => null,
            'max' => null,
            'col' => 6,
            'autogrow' => false,
            'hint' => 'How many collaborators each project on a free account can have. 0 means no limit.',
            'secret' => false,
        ],
        [
            'key' => 'premium_max_collaborators',
            'title' => 'Premium: Max Collaborators per Project',
            'value' => 0,
            'type' => 'number',
            'count' => null,
            'max' => null,
            'col' => 6,
            'autogrow' => false,
            'hint' => 'How many collaborators each project on a Premium account can have. 0 means no limit.',
            'secret' => false,
        ],
        [
            'key' => 'free_max_stem_size',
            'title' => 'Free: Max Stem Upload (MB)',
            'value' => 50,
            'type' => 'number',
            'count' => null,
            'max' => null,
            'col' => 6,
            'autogrow' => false,
            'hint' => 'The largest stem file a free account can upload, in MB. 0 means no limit.',
            'secret' => false,
        ],
        [
            'key' => 'premium_max_stem_size',
            'title' => 'Premium: Max Stem Upload (MB)',
            'value' => 500,
            'type' => 'number',
            'count' => null,
            'max' => null,
            'col' => 6,
            'autogrow' => false,
            'hint' => 'The largest stem file a Premium account can upload, in MB. 0 means no limit.',
            'secret' => false,
        ],
    ];

    /**
     * Add the payment settings to existing installs.
     */
    public function up(): void
    {
        foreach ($this->rows as $row) {
            if (! Configuration::where('key', $row['key'])->exists()) {
                Configuration::insert([$row]);
            }
        }

        Cache::forget('configuration::build');
    }

    public function down(): void
    {
        Configuration::whereIn('key', array_column($this->rows, 'key'))->delete();
        Cache::forget('configuration::build');
    }
};
