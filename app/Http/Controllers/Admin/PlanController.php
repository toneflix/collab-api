<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Feature;
use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Lets admins create and manage subscription plans and choose what each one
 * grants or limits from the feature catalogue.
 */
class PlanController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(function (Request $request, Closure $next) {
            abort_unless(
                $request->user()?->checkPermissionTo('manage-plans'),
                HttpStatus::FORBIDDEN->value,
                'You do not have permission to manage plans.'
            );

            return $next($request);
        });
    }

    public function index()
    {
        return PlanResource::collection(Plan::ordered()->get())->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * The catalogue of features a plan can grant or limit.
     */
    public function features()
    {
        return Providers::response()->success([
            'data' => Feature::catalogue(),
            'message' => HttpStatus::message(HttpStatus::OK),
        ]);
    }

    public function show(Plan $plan)
    {
        return (new PlanResource($plan))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    public function store(Request $request)
    {
        $plan = $this->save($request, new Plan);

        return (new PlanResource($plan))->additional([
            'message' => __(':name was created.', ['name' => $plan->name]),
            'status' => 'success',
            'status_code' => HttpStatus::CREATED,
        ])->response()->setStatusCode(HttpStatus::CREATED->value);
    }

    public function update(Request $request, Plan $plan)
    {
        $plan = $this->save($request, $plan);

        return (new PlanResource($plan))->additional([
            'message' => __(':name was saved.', ['name' => $plan->name]),
            'status' => 'success',
            'status_code' => HttpStatus::ACCEPTED,
        ])->response()->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Delete a plan nobody has subscribed to. A plan with subscriptions is
     * deactivated instead, so people who paid keep what they paid for.
     */
    public function destroy(Plan $plan)
    {
        if ($plan->is_default) {
            throw ValidationException::withMessages(['plan' => 'The default plan can\'t be deleted. Make another plan the default first.']);
        }

        if ($plan->subscriptions()->exists()) {
            $plan->update(['active' => false]);

            return (new PlanResource($plan))->additional([
                'message' => __(':name has subscribers, so it was deactivated instead of deleted.', ['name' => $plan->name]),
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])->response()->setStatusCode(HttpStatus::ACCEPTED->value);
        }

        $plan->delete();

        return Providers::response()->success([
            'data' => [],
            'message' => __(':name was deleted.', ['name' => $plan->name]),
        ], HttpStatus::ACCEPTED);
    }

    protected function save(Request $request, Plan $plan): Plan
    {
        $creating = ! $plan->exists;
        $required = $creating ? 'required' : 'sometimes';

        $rules = [
            'name' => [$required, 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:100', 'alpha_dash', Rule::unique('plans', 'slug')->ignore($plan->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'price' => [$required, 'numeric', 'min:0', 'max:99999999'],
            'interval' => [$required, Rule::in(Plan::INTERVALS)],
            'interval_count' => ['nullable', 'integer', 'min:1', 'max:120'],
            'billing_options' => ['nullable', 'array', 'max:10'],
            'billing_options.*.cycles' => ['required', 'integer', 'min:2', 'max:120', 'distinct'],
            'billing_options.*.discount' => ['required', 'numeric', 'min:0', 'max:100'],
            'features' => ['nullable', 'array'],
            'is_default' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'integer'],
        ];

        foreach (Feature::cases() as $feature) {
            $rules["features.{$feature->value}"] = $feature->type() === 'toggle'
                ? ['sometimes', 'boolean']
                : ['sometimes', 'integer', 'min:0'];
        }

        $data = $this->validate($request, $rules, [], [
            'interval_count' => 'interval count',
            'is_default' => 'default',
            'billing_options.*.cycles' => 'number of periods',
            'billing_options.*.discount' => 'discount',
        ]);

        $isDefault = $request->has('is_default') ? $request->boolean('is_default') : (bool) $plan->is_default;
        $price = (float) ($data['price'] ?? $plan->price);

        if ($isDefault && $price > 0) {
            throw ValidationException::withMessages(['is_default' => 'The default plan must be free.']);
        }

        if ($plan->is_default && ! $isDefault) {
            throw ValidationException::withMessages(['is_default' => 'Make another plan the default instead.']);
        }

        if ($isDefault && $request->has('active') && ! $request->boolean('active')) {
            throw ValidationException::withMessages(['active' => 'The default plan can\'t be deactivated.']);
        }

        // Unknown keys are dropped; missing keys keep their current value.
        $features = $plan->features ?? [];
        foreach (Feature::cases() as $feature) {
            if (array_key_exists($feature->value, $data['features'] ?? [])) {
                $features[$feature->value] = $feature->cast($data['features'][$feature->value]);
            }
        }

        return DB::transaction(function () use ($plan, $data, $features, $isDefault, $request, $creating) {
            if (array_key_exists('billing_options', $data)) {
                $data['billing_options'] = collect($data['billing_options'] ?? [])
                    ->map(fn ($option) => ['cycles' => (int) $option['cycles'], 'discount' => round((float) $option['discount'], 2)])
                    ->sortBy('cycles')->values()->all();
                $plan->billing_options = $data['billing_options'];
            }
            $plan->fill(collect($data)->except(['features', 'is_default', 'slug', 'billing_options'])->reject(fn ($value) => $value === null)->all());
            if (array_key_exists('description', $data)) {
                $plan->description = $data['description'];
            }
            $plan->features = $creating
                ? [...collect(Feature::cases())->mapWithKeys(fn (Feature $f) => [$f->value => $f->fallback()])->all(), ...$features]
                : $features;
            $plan->is_default = $isDefault;

            if ($request->filled('slug') || $creating) {
                $plan->slug = $request->input('slug') ?: $this->uniqueSlug($plan->name);
            }

            if ($isDefault) {
                Plan::whereKeyNot($plan->id ?? 0)->where('is_default', true)->update(['is_default' => false]);
                $plan->active = true;
            }

            $plan->save();

            return $plan;
        });
    }

    protected function uniqueSlug(string $name): string
    {
        $base = str($name)->slug()->toString() ?: 'plan';
        $slug = $base;
        $i = 1;

        while (Plan::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
