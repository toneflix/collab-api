<?php

namespace App\Http\Resources;

use App\Models\PayoutAccount;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * @mixin PayoutAccount
 */
class PayoutAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'provider' => $this->provider,
            'country' => $this->country,
            'method' => $this->method,
            'status' => $this->status,
            'bankCode' => $this->bank_code,
            'bankName' => $this->bank_name,
            'accountNumber' => $this->maskedNumber(),
            'accountName' => $this->account_name,
            'currency' => $this->currency,
            'updatedAt' => $this->updated_at,
        ];
    }
}
