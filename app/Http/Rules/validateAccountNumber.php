<?php

namespace App\Http\Rules;

use App\Helpers\Providers;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Yabacon\Paystack;

class validateAccountNumber implements DataAwareRule, ValidationRule
{
    /**
     * All of the data under validation.
     *
     * @var array<string, mixed>
     */
    protected $data = [];

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value || mb_strlen($value) != 10) {
            $fail('Invalid account number.');

            return;
        }

        $paystack = new Paystack(Providers::config('paystack_secret_key', env('PAYSTACK_SECRET_KEY'), true));

        $key = str($attribute)->beforeLast('.')->append('.bank_code')->toString();

        try {
            $paystack->bank->resolve([
                'account_number' => $value,
                'bank_code' => $this->data['bank_code'] ??
                    $this->data['user_data']['bank']['bank_code'] ??
                    Arr::dot($this->data)[$key] ?? '',
            ]);
        } catch (\Yabacon\Paystack\Exception\PaystackException $th) {
            $fail('We could not find your account number.');
        }
    }

    /**
     * Set the data under validation.
     *
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }
}
