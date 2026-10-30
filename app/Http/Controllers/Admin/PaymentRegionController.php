<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentRegionCollection;
use App\Http\Resources\PaymentRegionResource;
use App\Models\PaymentRegion;
use App\Services\Payment\PaymentGateways;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Toneflix\Money\Currencies;

/**
 * Lets admins send groups of countries to their own payment providers and currency.
 */
class PaymentRegionController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(function (Request $request, Closure $next) {
            abort_unless(
                $request->user()?->checkPermissionTo('manage-configuration'),
                HttpStatus::FORBIDDEN->value,
                'You do not have permission to change payment regions.'
            );

            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->input('search'));

        $regions = PaymentRegion::query()
            ->when($search !== '', fn(Builder $q) => $q->where('name', 'ilike', "%{$search}%"))
            ->oldest('id')
            ->paginate($request->perPage(30));

        return (new PaymentRegionCollection($regions))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    public function store(Request $request, PaymentGateways $gateways)
    {
        $region = $this->save($request, $gateways, new PaymentRegion);

        return (new PaymentRegionResource($region))
            ->additional([
                'message' => 'Payment region added.',
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }

    public function update(Request $request, PaymentGateways $gateways, PaymentRegion $region)
    {
        $region = $this->save($request, $gateways, $region);

        return (new PaymentRegionResource($region))
            ->additional([
                'message' => 'Payment region saved.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    public function destroy(PaymentRegion $region)
    {
        $region->delete();

        return (new PaymentRegionResource($region))
            ->additional([
                'message' => 'Payment region removed.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    protected function save(Request $request, PaymentGateways $gateways, PaymentRegion $region): PaymentRegion
    {
        $valid = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'countries' => ['required', 'array', 'min:1', 'max:250'],
            'countries.*' => ['required', 'string', 'size:2', 'alpha'],
            'providers' => ['required', 'array', 'min:1'],
            'providers.*' => ['required', 'string', 'distinct', Rule::in($gateways->keys())],
            'currency' => [
                'required',
                'string',
                'size:3',
                fn(string $attribute, mixed $value, Closure $fail) => Currencies::has((string) $value) || $fail('Choose a supported currency.'),
            ],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'rate_markup' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'active' => ['nullable', 'boolean'],
        ]);

        $countries = collect($valid['countries'])
            ->map(fn(string $code) => strtoupper($code))
            ->unique()
            ->values()
            ->all();

        $taken = PaymentRegion::query()
            ->active()
            ->covering($countries)
            ->when($region->exists, fn(Builder $q) => $q->whereKeyNot($region->id))
            ->value('name');

        if ($taken && ($valid['active'] ?? $region->active ?? true)) {
            throw ValidationException::withMessages(['countries' => "Some of these countries are already in {$taken}."]);
        }

        $region->fill([
            'name' => $valid['name'],
            'countries' => $countries,
            'providers' => array_values($valid['providers']),
            'currency' => strtoupper($valid['currency']),
            'exchange_rate' => $valid['exchange_rate'] ?? null,
            'rate_markup' => $valid['rate_markup'] ?? 0,
            'active' => $valid['active'] ?? $region->active ?? true,
        ]);
        $region->save();

        return $region;
    }
}
