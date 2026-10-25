<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Http\Resources\SafetyResource;
use App\Models\Safety;
use App\Services\Moderation\Moderation;
use App\Services\Moderation\Reportables;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SafetyController extends Controller
{
    /**
     * Report something
     */
    public function report(Request $request, Reportables $reportables, Moderation $moderation)
    {
        $copyright = in_array($request->input('reason'), Safety::$copyrightReasons, true);

        $valid = $request->validate([
            'reason' => ['required', 'string', Rule::in(Safety::$allowedReasons)],
            'type' => ['required', 'string', Rule::in($reportables->names())],
            'id' => ['required', 'integer'],
            'details' => [$copyright ? 'required' : 'nullable', 'string', 'min:10', 'max:2000'],
            'claim' => ['nullable', 'array'],
            'claim.original_url' => ['nullable', 'url', 'max:500'],
            'claim.claimant' => [$copyright ? 'required' : 'nullable', 'string', 'max:120'],
            'claim.email' => [$copyright ? 'required' : 'nullable', 'email', 'max:180'],
            'claim.statement' => [$copyright ? 'accepted' : 'nullable'],
        ], [
            'details.required' => 'Tell us which work is being copied and how.',
            'claim.statement.accepted' => 'Please confirm the statement to file a copyright claim.',
        ]);

        $class = $reportables->classFor($valid['type']);
        $item = $class::query()->findOrFail($valid['id']);

        $claim = $copyright ? array_filter([
            'original_url' => $valid['claim']['original_url'] ?? null,
            'claimant' => $valid['claim']['claimant'] ?? null,
            'email' => $valid['claim']['email'] ?? null,
            'statement' => true,
        ]) : [];

        $report = $moderation->report($request->user(), $item, $valid['reason'], $valid['details'] ?? null, $claim);

        return (new SafetyResource($report))->additional([
            'message' => __('Thank you for looking out. We\'re reviewing your report, and if this :0 breaks our Terms of service, it will be removed.', [$moderation->label($item)]),
            'status' => 'success',
            'status_code' => HttpStatus::CREATED,
        ])->response()->setStatusCode(HttpStatus::CREATED->value);
    }
}
