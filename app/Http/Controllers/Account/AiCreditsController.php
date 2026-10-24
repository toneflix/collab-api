<?php

namespace App\Http\Controllers\Account;

use App\Enums\Feature;
use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Controllers\Controller;
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
        return Providers::response()->success([
            'data' => [
                'access' => (bool) $request->user()->feature(Feature::AI_TOOLS),
                'balance' => Credits::for($request->user())->balance(),
                ...AiSettings::clientConfig(),
            ],
            'message' => HttpStatus::message(HttpStatus::OK),
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
            ->paginate((int) $request->input('limit', 20));

        return Providers::response()->success([
            'data' => collect($entries->items())->map(fn (AiCreditEntry $entry) => [
                'id' => $entry->id,
                'type' => $entry->type,
                'credits' => $entry->credits,
                'description' => $entry->description,
                'createdAt' => $entry->created_at,
            ])->all(),
            'meta' => ['total' => $entries->total(), 'page' => $entries->currentPage(), 'lastPage' => $entries->lastPage()],
            'message' => HttpStatus::message(HttpStatus::OK),
        ]);
    }
}
