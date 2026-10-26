<?php

namespace App\Services\Settings;

use App\Models\Configuration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

/**
 * The general app settings admins edit on the Configuration page.
 *
 * They live in the `configurations` table and are read with Providers::config().
 * Each field is key => [title, type, rules, hint].
 */
class AppConfiguration
{
    public const CURRENCIES = ['NGN', 'GHS', 'KES', 'ZAR', 'UGX', 'TZS', 'XOF', 'XAF', 'USD', 'EUR', 'GBP', 'CAD', 'AUD'];

    public const FIELDS = [
        'app_name' => ['App Name', 'text', ['required', 'string', 'max:60'], 'Shown in the app and in emails.'],
        'app_email' => ['Support Email', 'email', ['required', 'email', 'max:120'], 'Where people can reach you.'],
        'app_currency' => ['App Currency', 'string', ['required', 'in:'], 'Every price, balance and payout uses it. Paystack only pays creators out when it is NGN, GHS, KES or ZAR. Changing it does not convert existing prices or balances.'],
        'creator_share' => ['Creator Share (%)', 'number', ['required', 'integer', 'between:0,100'], 'Part of each sale and tip that goes to the creator.'],
        'min_tip_amount' => ['Minimum Tip', 'number', ['required', 'integer', 'min:0'], 'Smallest tip, in the app currency.'],
        'min_payout_amount' => ['Minimum Withdrawal', 'number', ['required', 'integer', 'min:0'], 'Smallest withdrawal, in the app currency.'],
        'payout_hold_days' => ['Earnings Clearing Days', 'number', ['required', 'integer', 'between:0,90'], 'Days before earnings can be withdrawn.'],
        'payout_auto_approve' => ['Send Withdrawals Automatically', 'boolean', ['required', 'boolean'], 'Send withdrawals without an admin approving each one.'],
        'review_releases' => ['Review Releases', 'boolean', ['required', 'boolean'], 'Hold published releases in a review queue until an admin approves them.'],
        'verify_email' => ['Verify Email', 'boolean', ['required', 'boolean'], 'Ask new users to confirm their email.'],
        'verify_phone' => ['Verify Phone', 'boolean', ['required', 'boolean'], 'Ask new users to confirm their phone number.'],
        'studio_plugins' => ['Studio Plugins', 'boolean', ['required', 'boolean'], 'Let people add Web Audio Module plugins from other sites to their stems. Plugins run code from those sites in the browser, and stems using them are recorded and uploaded when saved.'],
        'reference_prefix' => ['Payment Reference Prefix', 'string', ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9_-]+$/'], 'Starts every payment reference.'],
    ];

    /**
     * @return array<string, array{title: string, type: string, hint: string, value: mixed, options?: list<string>}>
     */
    public static function forAdmin(): array
    {
        $stored = Configuration::query()
            ->whereIn('key', array_keys(self::FIELDS))
            ->get()
            ->keyBy('key');

        return collect(self::FIELDS)->map(function (array $field, string $key) use ($stored) {
            $out = [
                'title' => $field[0],
                'type' => $field[1],
                'hint' => $field[3],
                'value' => $stored->get($key)?->value,
            ];

            if ($key === 'app_currency') {
                $out['options'] = self::CURRENCIES;
            }

            return $out;
        })->all();
    }

    /**
     * Validate and save the given settings. Keys that are not listed are ignored.
     *
     * @param  array<string, mixed>  $input
     */
    public static function update(array $input): void
    {
        $valid = Validator::make($input, self::rules())
            ->validate();

        foreach ($valid as $key => $value) {
            $configuration = Configuration::query()
                ->firstOrNew(['key' => $key]);

            // The type is set before the value, because it decides how the value is stored.
            if (! $configuration->exists) {
                $configuration->forceFill(['key' => $key, 'type' => self::FIELDS[$key][1], 'title' => self::FIELDS[$key][0]]);
            }

            $configuration->value = self::FIELDS[$key][1] === 'boolean'
                ? filter_var($value, FILTER_VALIDATE_BOOLEAN)
                : $value;
            $configuration->save();
        }

        Cache::forget('configuration::build');
    }

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return collect(self::FIELDS)->map(function (array $field, string $key) {
            $rules = array_map(fn(string $rule) => $rule === 'in:' ? 'in:' . implode(',', self::CURRENCIES) : $rule, $field[2]);

            // Every field is optional on update; only the ones sent are saved.
            return ['sometimes', ...$rules];
        })->all();
    }
}
