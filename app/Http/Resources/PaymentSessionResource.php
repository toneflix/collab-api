<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * A started checkout: the transaction to pay and where the gateway takes the payer.
 */
class PaymentSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->resource['transaction']->reference,
            'amount' => $this->resource['transaction']->due,
            'access_code' => $this->resource['access_code'],
            'authorization_url' => $this->resource['authorization_url'],
        ];
    }
}
