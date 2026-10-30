<?php

namespace App\Http\Resources;

use App\Models\PaymentRegion;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * @mixin PaymentRegion
 */
class PaymentRegionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'countries' => $this->countries ?? [],
            'providers' => $this->providers ?? [],
            'currency' => $this->currency,
            'exchangeRate' => $this->exchange_rate,
            'rateMarkup' => $this->rate_markup,
            'active' => $this->active,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
