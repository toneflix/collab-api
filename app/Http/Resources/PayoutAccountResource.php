<?php

namespace App\Http\Resources;

use App\Models\PayoutAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PayoutAccount
 */
class PayoutAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'bankCode' => $this->bank_code,
            'bankName' => $this->bank_name,
            'accountNumber' => $this->maskedNumber(),
            'accountName' => $this->account_name,
            'currency' => $this->currency,
            'updatedAt' => $this->updated_at,
        ];
    }
}
