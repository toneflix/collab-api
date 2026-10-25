<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * Countries, banks or an account name looked up from the payout providers, as they return them.
 */
class PayoutLookupResource extends JsonResource
{
    /**
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return (array) $this->resource;
    }
}
