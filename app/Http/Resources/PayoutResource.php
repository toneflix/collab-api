<?php

namespace App\Http\Resources;

use App\Models\Payout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payout
 */
class PayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'reason' => $this->failure_reason,
            'bank' => [
                'name' => $this->destination['bank_name'] ?? null,
                'accountNumber' => $this->destination['account_number'] ?? null,
                'accountName' => $this->destination['account_name'] ?? null,
            ],
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'username' => $this->user->username,
                'fullname' => $this->user->fullname,
                'imageUrl' => $this->user->images['image'] ?? null,
            ]),
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? [
                'id' => $this->reviewer->id,
                'fullname' => $this->reviewer->fullname,
            ] : null),
            'transferCode' => $this->when($request->is('api/admin/*'), $this->transfer_code),
            'reviewedAt' => $this->reviewed_at,
            'paidAt' => $this->paid_at,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
