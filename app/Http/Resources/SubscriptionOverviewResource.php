<?php

namespace App\Http\Resources;

use App\Helpers\Providers;
use App\Models\User;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * A user's plan, what it includes, and when the paid time runs out.
 *
 * @mixin User
 */
class SubscriptionOverviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $current = $this->subscriptions()->active()->first();
        $latest = $this->subscriptions()->first();
        $plan = $this->current_plan;

        return [
            'premium' => (bool) $current,
            // When the paid time runs out, including periods paid in advance
            'endsAt' => $current ? $latest?->ends_at : null,
            'current' => $current ? new SubscriptionResource($current) : null,
            'plan' => new PlanResource($plan),
            // 0 means no limit
            'features' => $plan->allFeatures(),
            'currency' => Providers::config('app_currency', 'NGN'),
        ];
    }
}
