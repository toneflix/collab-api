<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subscription
 */
class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan' => $this->plan,
            'planName' => $this->planModel?->name,
            'amount' => $this->amount,
            'startsAt' => $this->starts_at,
            'endsAt' => $this->ends_at,
        ];
    }
}
