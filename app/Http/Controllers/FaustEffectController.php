<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Http\Resources\FaustEffectResource;
use App\Services\Studio\Faust\FaustLibrary;

class FaustEffectController extends Controller
{
    /**
     * The studio's Faust effects. Empty when the server can't render them.
     */
    public function index(FaustLibrary $library)
    {
        $effects = $library->all();

        return FaustEffectResource::collection($effects)
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }
}
