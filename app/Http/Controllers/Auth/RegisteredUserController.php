<?php

namespace App\Http\Controllers\Auth;

use App\Enums\HttpStatus;
use App\Helpers\Providers as PV;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Account\Interests;
use App\Services\Auth\CodeClock;
use App\Services\Referrals\Referrals;
use App\Services\Referrals\Royalties;
use Closure;
use DeviceDetector\DeviceDetector;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

class RegisteredUserController extends Controller
{
    public function __construct(
        protected Referrals $referrals,
        protected Royalties $royalties,
    ) {
        parent::__construct();
    }

    /**
     * Handle an incoming registration request.
     *
     * @return Response
     *
     * @throws ValidationException
     */
    public function store(Request $request)
    {
        $this->validate($request, [
            'name' => ['required_without:firstname', 'string', 'max:255'],
            'email' => ['required_without:phone', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => 'required_without:email|nullable|string|max:255|unique:users,phone',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'firstname' => ['nullable', 'string', 'max:255'],
            'lastname' => ['nullable', 'string', 'max:255'],
            'accept' => ['required', 'accepted'],
            'referral_code' => [
                'nullable',
                'string',
                'max:40',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! $this->referrals->findReferrer($value)) {
                        $fail('That invite code is not valid.');
                    }
                },
            ],
            'royalty_token' => [
                'nullable',
                'string',
                'max:64',
                function (string $attribute, mixed $value, Closure $fail) use ($request) {
                    $invite = $this->royalties->findOpenInvite($value);

                    if (! $invite) {
                        $fail('This royalty invite is no longer open.');
                    } elseif ($invite->email !== str($request->input('email'))->trim()->lower()->toString()) {
                        $fail('Sign up with the email address the invite was sent to.');
                    }
                },
            ],
            ...Interests::identityRules($request->input('account_type'), true),
        ], [
            'account_type.required' => 'Choose how you\'ll use Toneflix Music.',
            'identities.*.in' => 'Pick from the options for your account type.',
            'name.required_without' => 'Please enter your fullname.',
            'accept' => 'You must accept our terms and conditions and privacy policy.',
        ], [
            'email' => 'Email Address',
            'phone' => 'Phone Number',
        ]);

        $user = $this->createUser($request);
        $referrer = $this->referrals->findReferrer($request->input('referral_code'));

        $invite = $this->royalties->findOpenInvite($request->input('royalty_token'));

        if ($referrer) {
            $this->referrals->attach($user, $referrer);
        }

        if ($invite) {
            $this->royalties->accept($invite, $user);
        }

        return $this->setUserData($request, $user);
    }

    /**
     * Create a new user based on the provided data.
     *
     * @return User
     */
    public function createUser(Request $request)
    {
        $firstname = str($request->get('name'))
            ->explode(' ')
            ->first(null, $request->firstname);
        $lastname = str($request->get('name'))
            ->explode(' ')
            ->last(fn($n) => $n !== $firstname, $request->lastname);

        $user = User::create([
            'account_type' => $request->get('account_type'),
            'identities' => array_values($request->input('identities', [])),
            'email' => $request->get('email'),
            'phone' => $request->get('phone'),
            'password' => $request->get('password'),
            'lastname' => $request->get('lastname', $lastname ?? ''),
            'firstname' => $request->get('firstname', $firstname),
            'email_verified_at' => ! PV::config('verify_email', false) ? now() : null,
            'phone_verified_at' => ! PV::config('verify_phone', false) ? now() : null,
        ]);

        return $user;
    }

    public function setUserData(Request $request, User $user)
    {
        event(new Registered($user));

        $dev = new DeviceDetector($request->userAgent());

        $device = $dev->getBrandName()
            ? ($dev->getBrandName() . $dev->getDeviceName())
            : $request->userAgent();

        $user->save();

        $token = $user->createToken($device, ['user:access'])->plainTextToken;

        return $this->preflight($token);
    }

    /**
     * Log the newly registered user in.
     *
     * @param  string  $token
     * @return UserResource
     */
    public function preflight($token)
    {
        [$id, $user_token] = explode('|', $token, 2);

        $token_data = DB::table('personal_access_tokens')
            ->where('token', hash('sha256', $user_token))
            ->first();

        $user_id = $token_data->tokenable_id;

        Auth::loginUsingId($user_id);

        /** @var User $user */
        $user = Auth::user();

        return (new UserResource($user))
            ->additional([
                ...CodeClock::retry($user->last_attempt),
                'message' => 'Your account is ready.',
                'token' => $token,
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }
}
