<?php

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * @mixin Plan
 */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'interval' => $this->interval,
            'intervalCount' => $this->interval_count,
            'billingOptions' => $this->billingOptions(),
            'features' => $this->allFeatures(),
            'isDefault' => $this->is_default,
            'active' => $this->active,
            'sort' => $this->sort,
            $this->mergeWhen($request->routeIs('admin.*'), fn() => [
                'subscribers' => $this->subscriptions()->active()->distinct('user_id')->count('user_id'),
                'createdAt' => $this->created_at,
            ]),
        ];
    }
}
