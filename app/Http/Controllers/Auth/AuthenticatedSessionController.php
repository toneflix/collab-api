<?php

namespace App\Http\Controllers\Auth;

use App\Enums\HttpStatus;
use App\Helpers\Providers as PV;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\DeviceResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use DeviceDetector\DeviceDetector;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    /**
     * Handle an incoming authentication request.
     *
     * @return Response
     */
    public function store(LoginRequest $request)
    {
        try {
            $request->authenticate();
            $user = $request->user();

            return $this->setUserData($request, $user);
        } catch (ValidationException $e) {
            return PV::response()->error([
                'message' => $e->getMessage(),
                'errors' => [
                    'email' => $e->getMessage(),
                ],
            ], HttpStatus::UNPROCESSABLE_ENTITY);
        }
    }

    public function setUserData(Request|LoginRequest $request, User $user)
    {
        $device = $request->userAgent();
        $user->tokens()->where('name', $device)->delete();
        $token = $user->createToken($device, ['user:access']);

        $user->save();

        return PV::response()->success(new UserResource($user), HttpStatus::OK, [
            'message' => 'Login was successfull',
            'token' => $token->plainTextToken,
            'abilities' => $token->accessToken->abilities,
        ]);
    }

    public function getTokens(Request $request)
    {
        $request->validate(['limit' => ['nullable', 'integer', 'between:1,50']]);

        $tokens = $request->user()->tokens()
            ->latest('last_used_at')
            ->paginate((int) $request->input('limit', 20));

        return DeviceResource::collection($tokens)->additional([
            'message' => 'Tokens retrieved successfully',
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Destroy an authenticated session.
     *
     * @return Response
     */
    public function destroy(Request $request)
    {
        $request->user()->update([
            'last_seen' => now(),
        ]);

        $request->user()->currentAccessToken()->delete();

        if (! $request->isXmlHttpRequest()) {
            session()->flush();

            return response()->redirectToRoute('web.login');
        }

        return PV::response()->success([
            'message' => 'You have been successfully logged out',
        ], HttpStatus::OK);
    }

    /**
     * Destroy all selected authenticated sessions.
     *
     * @return Response
     */
    public function destroyTokens(Request $request)
    {
        $request->validate([
            'token_ids' => 'required|array|max:100',
        ], [
            'token_ids.required' => __('Please select at least one device to logout'),
        ]);

        $tokens = $request->user()->tokens()
            ->whereIn('id', $request->token_ids)
            ->whereNot('id', $request->user()->currentAccessToken()->id)
            ->get();

        $names = [];

        if ($tokens->count() > 0) {
            $names = $tokens->pluck('name')->map(function ($name) {
                $dev = new DeviceDetector($name);
                $dev->parse();
                $os = $dev->getOs();

                $osname = $os['name'] ?? 'Unknown Device';
                $osversion = $os['version'] ?? '0.00';

                return collect([$dev->getBrandName(), $osname, "(v{$osversion})"])->implode(' ');
            })->implode(', ');

            $tokens->each->delete();
        } else {
            return PV::response()->error([
                'message' => __('You are no longer logged in on any of the selected devices'),
            ], HttpStatus::UNPROCESSABLE_ENTITY);
        }

        return PV::response()->success([
            'message' => __('You have been successfully logged out of :0', [$names]),
        ], HttpStatus::OK);
    }

    /**
     * Authenticate the request for channel access.
     *
     * @return Response
     */
    public function broadcastingAuth(Request $request)
    {
        return Broadcast::auth($request);
    }
}
