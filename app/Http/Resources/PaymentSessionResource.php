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
            'provider' => $this->resource['transaction']->method,
            'amount' => $this->resource['transaction']->due,
            'currency' => $this->resource['transaction']->currency,
            'chargeAmount' => $this->resource['transaction']->charge_amount,
            'accessCode' => $this->resource['access_code'],
            'authorizationUrl' => $this->resource['authorization_url'],
            'returnUrl' => $this->resource['return_url'],
        ];
    }
}
