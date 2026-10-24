<?php

namespace App\Http\Resources;

use App\Models\Release;
use App\Models\Track;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user('sanctum');
        $item = $this->transactable;
        $seesEarnings = ($viewer && (int) $viewer->id === (int) $this->recipient_id)
            || ($request->routeIs('admin.users.transactions') && $request->input('side') === 'earnings');

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'purpose' => $this->purpose,
            'status' => $this->status,
            'amount' => $this->amount,
            // Only the creator and admins see what the creator earned from it
            'earned' => $this->when($seesEarnings, $this->recipient_share),
            'message' => $this->meta['message'] ?? null,
            'plan' => $this->meta['plan'] ?? null,
            'planName' => $this->meta['title'] ?? null,
            'item' => match (true) {
                $item instanceof Release => [
                    'type' => 'release',
                    'id' => $item->id,
                    'slug' => $item->slug,
                    'title' => $item->title,
                    'artist' => $item->artist?->username,
                    'imageUrl' => $item->files['artwork'] ?? null,
                ],
                $item instanceof Track => [
                    'type' => 'track',
                    'id' => $item->id,
                    'slug' => $item->slug,
                    'title' => $item->title,
                    'artist' => $item->artist?->username,
                    'imageUrl' => $item->files['image'] ?? null,
                ],
                $item instanceof User && $this->purpose === 'tip' => [
                    'type' => 'user',
                    'id' => $item->id,
                    'username' => $item->username,
                    'title' => $item->fullname,
                    'imageUrl' => $item->images['image'] ?? null,
                ],
                default => null,
            },
            'payer' => $this->when(
                $seesEarnings && $this->user,
                fn () => [
                    'id' => $this->user->id,
                    'username' => $this->user->username,
                    'fullname' => $this->user->fullname,
                ]
            ),
            'paidAt' => $this->paid_at,
            'createdAt' => $this->created_at,
        ];
    }
}
