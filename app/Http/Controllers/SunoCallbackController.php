<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Http\Resources\ActionResource;

/**
 * Takes Suno's task callbacks. They carry no signature, so results are only
 * read by polling Suno, and this just answers so Suno stops retrying.
 */
class SunoCallbackController extends Controller
{
    public function __invoke()
    {
        $result = ['received' => true];

        return (new ActionResource($result))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }
}
