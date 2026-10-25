<?php

namespace App\Http\Rules;

use App\Services\Payment\PaystackTransfers;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Translation\PotentiallyTranslatedString;
use Toneflix\Paystack\Exception\ApiException;
use Toneflix\Paystack\Paystack;

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
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value || mb_strlen($value) != 10) {
            $fail('Invalid account number.');

            return;
        }

        $paystack = new Paystack((string) config('services.paystack.secret_key'));

        $key = str($attribute)
            ->beforeLast('.')
            ->append('.bank_code')
            ->toString();

        try {
            (new PaystackTransfers($paystack))->resolveAccount([
                'account_number' => $value,
                'bank_code' => $this->data['bank_code'] ??
                    $this->data['user_data']['bank']['bank_code'] ??
                    Arr::dot($this->data)[$key] ?? '',
            ]);
        } catch (ApiException) {
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
