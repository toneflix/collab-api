<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Controllers\Controller;
use App\Services\Settings\AppConfiguration;
use Closure;
use Illuminate\Http\Request;

/**
 * Lets admins change the general app settings: currency, creator share, payout limits and sign-up checks.
 */
class ConfigurationController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(function (Request $request, Closure $next) {
            abort_unless(
                $request->user()?->checkPermissionTo('manage-configuration'),
                HttpStatus::FORBIDDEN->value,
                'You do not have permission to change the app configuration.'
            );

            return $next($request);
        });
    }

    public function show()
    {
        return $this->settings();
    }

    public function update(Request $request)
    {
        AppConfiguration::update($request->all());

        return $this->settings('Configuration saved.', HttpStatus::ACCEPTED);
    }

    protected function settings(string $message = 'OK', HttpStatus $status = HttpStatus::OK)
    {
        return Providers::response()->success([
            'data' => ['fields' => AppConfiguration::forAdmin()],
            'message' => $message,
        ], $status);
    }
}
