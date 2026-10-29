<?php

namespace App\Http\Controllers;

use App\Enums\Feature;
use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Resources\PlanCollection;
use App\Http\Resources\PlanResource;
use App\Models\Plan;

class PlanController extends Controller
{
    /**
     * The plans on offer, what each one includes, and the plan the viewer is on.
     */
    public function index()
    {
        // Admins add plans by hand, so the list stays short.
        $plans = Plan::onOffer()
            ->ordered()
            ->get();

        $current = $this->user?->current_plan;

        return (new PlanCollection($plans))
            ->additional([
                // The billing periods on offer, for filtering plans, e.g. Monthly or Annual
                'periods' => Plan::periods($plans),
                'features' => Feature::catalogue(),
                'current' => $current ? new PlanResource($current) : null,
                'currency' => Providers::config('app_currency', 'NGN'),
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }
}
