<?php

namespace App\Http\Controllers\Account;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TakedownCollection;
use App\Http\Resources\TakedownResource;
use App\Models\Takedown;
use App\Services\Moderation\Moderation;
use App\Services\Moderation\ModerationQueue;
use Illuminate\Http\Request;

/**
 * Takedowns of the signed-in person's content, and their appeals.
 */
class TakedownController extends Controller
{
    public function index(Request $request, ModerationQueue $queue)
    {
        $request->validate(['limit' => ['nullable', 'integer', 'between:1,50']]);

        $takedowns = $queue->takedownsFor($request->user(), (int) $request->input('limit', 20));

        return (new TakedownCollection($takedowns))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    public function appeal(Request $request, Takedown $takedown, Moderation $moderation)
    {
        $valid = $request->validate([
            'message' => ['required', 'string', 'min:20', 'max:3000'],
        ], [
            'message.min' => 'Tell us why this should come back, for example that you own the rights or have a licence.',
        ]);

        $moderation->appeal($takedown, $request->user(), $valid['message']);

        $takedown->refresh();

        return (new TakedownResource($takedown))
            ->additional([
                'message' => 'Appeal sent. A moderator will look at it again.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
