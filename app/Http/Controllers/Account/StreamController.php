<?php

namespace App\Http\Controllers\Account;

use App\Enums\Feature;
use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActionResource;
use App\Services\Streams\StreamEarnings;
use App\Services\Streams\StreamRules;

class StreamController extends Controller
{
    /**
     * The creator's paid streams this month, an estimate, and what streams earned so far.
     */
    public function show(StreamEarnings $earnings)
    {
        $summary = [
            ...$earnings->summary($this->user),
            'eligible' => (bool) $this->user->feature(Feature::EARN_STREAMS),
            'rules' => StreamRules::current()->toArray(),
        ];

        return (new ActionResource($summary))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }
}
