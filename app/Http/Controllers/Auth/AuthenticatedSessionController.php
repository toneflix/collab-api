<?php

namespace App\Http\Controllers\Auth;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\ActionResource;
use App\Http\Resources\DeviceCollection;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Account\Deactivation;
use App\Services\Auth\TwoFactor;
use DeviceDetector\DeviceDetector;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    /**
     * Handle an incoming authentication request.
     *
     * @return Response
     */
    public function store(LoginRequest $request, TwoFactor $twoFactor)
    {
        $request->authenticate();

        $user = $request->user();

        if (! $twoFactor->enabled($user)) {
            return $this->signIn($request, $user);
        }

        Auth::guard('web')->logout();

        $challenge = $twoFactor->challenge($user, $request->boolean('remember'));
        $result = ['twoFactor' => true, 'challenge' => $challenge];

        return (new ActionResource($result))
            ->additional([
                'message' => __('Enter the code from your authenticator app.'),
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Finish signing in with a code from the authenticator app or a recovery
     * code.
     */
    public function twoFactor(Request $request, TwoFactor $twoFactor)
    {
        $valid = $request->validate([
            'challenge' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:20'],
        ]);

        $user = $twoFactor->answer($valid['challenge'], $valid['code']);

        if ($user->isSuspended()) {
            throw ValidationException::withMessages(['code' => $user->suspensionMessage()]);
        }

        Auth::setUser($user);

        return $this->signIn($request, $user);
    }

    /**
     * Give a user who proved who they are a token, bringing their account
     * back when it was deactivated.
     */
    protected function signIn(Request $request, User $user)
    {
        $message = Deactivation::reactivate($user)
            ? __('Welcome back. Your account is active again.')
            : null;

        return $this->setUserData($request, $user, $message);
    }

    public function setUserData(Request|LoginRequest $request, User $user, ?string $message = null)
    {
        $device = $request->userAgent();
        $user
            ->tokens()
            ->where('name', $device)
            ->delete();
        $token = $user->createToken($device, ['user:access']);

        $user->save();

        return (new UserResource($user))
            ->additional([
                'message' => $message ?? 'Login was successfull',
                'token' => $token->plainTextToken,
                'abilities' => $token->accessToken->abilities,
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ])
            ->response()
            ->setStatusCode(HttpStatus::OK->value);
    }

    public function getTokens(Request $request)
    {
        $request->validate(['limit' => ['nullable', 'integer', 'between:1,50']]);

        $tokens = $request
            ->user()
            ->tokens()
            ->latest('last_used_at')
            ->paginate($request->perPage(20, 50));

        return (new DeviceCollection($tokens))
            ->additional([
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
        $request
            ->user()
            ->update([
                'last_seen' => now(),
            ]);

        $request
            ->user()
            ->currentAccessToken()
            ->delete();

        if (! $request->isXmlHttpRequest()) {
            session()
                ->flush();

            return response()
                ->redirectToRoute('web.login');
        }

        $result = ['loggedOut' => true];

        return (new ActionResource($result))
            ->additional([
                'message' => 'You have been successfully logged out',
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
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

        $tokens = $request
            ->user()
            ->tokens()
            ->whereIn('id', $request->token_ids)
            ->whereNot('id', $request->user()->currentAccessToken()->id)
            ->get();

        abort_if(
            $tokens->isEmpty(),
            HttpStatus::UNPROCESSABLE_ENTITY->value,
            __('You are no longer logged in on any of the selected devices'),
        );

        $names = $tokens
            ->pluck('name')
            ->map(function ($name) {
                $dev = new DeviceDetector($name);
                $dev->parse();
                $os = $dev->getOs();

                $osname = $os['name'] ?? 'Unknown Device';
                $osversion = $os['version'] ?? '0.00';

                return collect([$dev->getBrandName(), $osname, "(v{$osversion})"])->implode(' ');
            })
            ->implode(', ');

        $tokens->each->delete();

        $result = ['loggedOut' => $tokens->pluck('id')->all()];

        return (new ActionResource($result))
            ->additional([
                'message' => __('You have been successfully logged out of :0', [$names]),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
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
