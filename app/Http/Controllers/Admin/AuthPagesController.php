<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SettingsResource;
use App\Services\Settings\AuthPages;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lets admins pick the auth page layout, banner image and text.
 */
class AuthPagesController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(function (Request $request, Closure $next) {
            abort_unless(
                $request->user()?->checkPermissionTo('manage-configuration'),
                HttpStatus::FORBIDDEN->value,
                'You do not have permission to change the auth pages.'
            );

            return $next($request);
        });
    }

    public function show(): JsonResponse
    {
        return $this->settings();
    }

    public function update(Request $request): JsonResponse
    {
        AuthPages::update($request->only(['layout', 'texts']));

        return $this->settings('Auth pages saved.', HttpStatus::ACCEPTED);
    }

    public function banner(Request $request): JsonResponse
    {
        $request->validate([
            'banner' => ['required', 'image', 'max:5120'],
        ]);

        AuthPages::setBanner($request->file('banner'));

        return $this->settings('Banner saved.', HttpStatus::ACCEPTED);
    }

    public function removeBanner(): JsonResponse
    {
        AuthPages::setBanner(null);

        return $this->settings('Banner removed.', HttpStatus::ACCEPTED);
    }

    protected function settings(string $message = 'OK', HttpStatus $status = HttpStatus::OK): JsonResponse
    {
        $settings = AuthPages::forAdmin();

        return (new SettingsResource($settings))
            ->additional([
                'message' => $message,
                'status' => 'success',
                'status_code' => $status,
            ])
            ->response()
            ->setStatusCode($status->value);
    }
}
