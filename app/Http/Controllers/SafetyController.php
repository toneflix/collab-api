<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Controllers\Controller;
use App\Http\Resources\SafetyResource;
use App\Models\Safety;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SafetyController extends Controller
{
    /**
     * Report something
     */
    public function report(Request $request)
    {
        $request->validate([
            'reason' => ['required', 'string', Rule::in(Safety::$allowedReasons)],
            'type' => ['required', 'string', 'in:feed,comment,user'],
            'id' => [Rule::exists(str($request->type)->plural()->toString(), 'id')],
        ]);

        $model_type = str($request->type)->ucfirst()->prepend('App\\Models\\')->toString();
        if (!class_exists($model_type)) {
            return Providers::response()->error(['message' => 'Invalid report type'], HttpStatus::UNPROCESSABLE_ENTITY);
        }

        $safety = Safety::query()->firstOrNew([
            'model_type' => $model_type,
            'model_id' => $request->id,
        ], [
            'reason' => $request->reason,
            'type' => 'report',
        ]);

        $safety->user_id = $request->user()->id;
        $safety->save();

        $labels = [
            'comment' => 'comment',
            'report' => 'report',
            'feed' => 'post',
            'user' => 'account',
        ];

        return (new SafetyResource($safety))->additional([
            'message' => __('Thank you for looking out, we are reviewing your report and if the :0 is found to be in violation of any of our Terms of service, it will be permanently removed.', [$labels[$request->type]]),
            'status' => 'success',
            'status_code' => HttpStatus::CREATED,
        ])->response()->setStatusCode(HttpStatus::CREATED->value);
    }
}
