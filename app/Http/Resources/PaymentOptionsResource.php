<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * How the user pays: their currency, its rate from the app currency, and the providers they can use.
 */
class PaymentOptionsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'country' => $this->resource['country'],
            'currency' => $this->resource['currency'],
            'rate' => $this->resource['rate'],
            'providers' => $this->resource['providers'],
            'region' => $this->resource['region'],
        ];
    }
}
