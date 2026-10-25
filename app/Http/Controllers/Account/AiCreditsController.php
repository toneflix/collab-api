<?php

namespace App\Http\Controllers\Account;

use App\Enums\Feature;
use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiAccountResource;
use App\Http\Resources\AiCreditEntryCollection;
use App\Models\AiCreditEntry;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Credits;
use Illuminate\Http\Request;

/**
 * The signed-in user's AI credits: what's left, what packs cost and what
 * changed their balance.
 */
class AiCreditsController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        $account = [
            'access' => (bool) $user->feature(Feature::AI_TOOLS),
            'balance' => Credits::for($user)->balance(),
            ...AiSettings::clientConfig(),
        ];

        return (new AiAccountResource($account))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    public function history(Request $request)
    {
        $request->validate([
            'limit' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $entries = AiCreditEntry::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->paginate($request->perPage(20));

        return (new AiCreditEntryCollection($entries))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }
}
