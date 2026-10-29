<?php

namespace App\Http\Resources;

use App\Models\StreamPeriod;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * @mixin StreamPeriod
 */
class StreamPeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'month' => $this->month->format('Y-m'),
            'status' => $this->status,
            'revenue' => $this->revenue,
            'share' => $this->share,
            'pool' => $this->pool,
            'plays' => $this->plays,
            'weighted' => $this->weighted,
            'rate' => $this->rate,
            'earners' => $this->earners,
            'paidOut' => $this->paid_out,
            'weights' => $this->weights,
            'settledAt' => $this->settled_at,
        ];
    }
}
