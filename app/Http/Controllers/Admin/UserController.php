<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminActionCollection;
use App\Http\Resources\AdminUserCollection;
use App\Http\Resources\AdminUserResource;
use App\Http\Resources\TransactionCollection;
use App\Models\Plan;
use App\Models\User;
use App\Services\Admin\UserDirectory;
use App\Services\Admin\UserInsights;
use App\Services\Admin\UserManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Lets admins find users, see how they use the app, and manage their accounts.
 */
class UserController extends Controller
{
    public function __construct(protected UserManager $manager)
    {
        parent::__construct();

        $this->middleware(function (Request $request, Closure $next) {
            abort_unless(UserManager::allows($request->user()), HttpStatus::FORBIDDEN->value, 'You do not have permission to manage users.');

            return $next($request);
        });
    }

    public function index(Request $request, UserDirectory $directory)
    {
        $valid = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(UserDirectory::STATUSES)],
            'role' => ['nullable', 'string', 'max:50'],
            'permission' => ['nullable', 'string', 'max:50'],
            'sort' => ['nullable', Rule::in(UserDirectory::SORTS)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $users = $directory
            ->query($valid)
            ->withCount(['projects', 'collabProjects', 'releases'])
            ->with(['roles', 'subscriptions' => fn($q) => $q->active()->with('planModel')])
            ->paginate($request->perPage(20, 50));

        return (new AdminUserCollection($users))
            ->additional([
                'roles' => config('permission-defs.roles', []),
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    public function show(Request $request, UserInsights $insights, User $managedUser)
    {
        $stats = $insights->for($managedUser);

        return $this->user($request, $managedUser, HttpStatus::message(HttpStatus::OK), ['stats' => $stats]);
    }

    public function update(Request $request, User $managedUser)
    {
        $this->manager->authorize($request->user(), 'update', $managedUser);

        $data = $request->validate($this->manager->profileRules($managedUser));

        $this->manager->update($request->user(), $managedUser, $data);

        return $this->user($request, $managedUser, 'The profile was saved.');
    }

    /**
     * Suspend for a number of days, or ban when no number is given.
     */
    public function suspend(Request $request, User $managedUser)
    {
        $this->manager->authorize($request->user(), 'suspend', $managedUser);

        $valid = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $until = isset($valid['days']) ? Carbon::now()->addDays((int) $valid['days']) : null;
        $this->manager->suspend($request->user(), $managedUser, $until, $valid['reason']);

        return $this->user($request, $managedUser, $until ? 'The account was suspended.' : 'The account was banned.');
    }

    public function unsuspend(Request $request, User $managedUser)
    {
        $this->manager->authorize($request->user(), 'suspend', $managedUser);

        $valid = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $this->manager->unsuspend($request->user(), $managedUser, $valid['reason'] ?? null);

        return $this->user($request, $managedUser, 'The account was restored.');
    }

    public function verify(Request $request, User $managedUser)
    {
        $this->manager->authorize($request->user(), 'verify', $managedUser);

        $valid = $request->validate([
            'verified' => ['required', 'boolean'],
            'email' => ['nullable', 'boolean'],
        ]);

        $this->manager->verify($request->user(), $managedUser, (bool) $valid['verified'], (bool) ($valid['email'] ?? false));

        return $this->user($request, $managedUser, $managedUser->verified ? 'The account is verified.' : 'The verified badge was removed.');
    }

    public function roles(Request $request, User $managedUser)
    {
        $this->manager->authorize($request->user(), 'roles', $managedUser);

        $valid = $request->validate([
            'roles' => ['present', 'array'],
            'roles.*' => ['string', Rule::in(config('permission-defs.roles', []))],
        ]);

        $this->manager->syncRoles($request->user(), $managedUser, $valid['roles']);

        return $this->user($request, $managedUser, 'The roles were saved.');
    }

    public function assignPlan(Request $request, User $managedUser)
    {
        $this->manager->authorize($request->user(), 'plan', $managedUser);

        $valid = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')],
            'cycles' => ['nullable', 'integer', 'min:1', 'max:120'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $plan = Plan::query()
            ->findOrFail($valid['plan_id']);
        $this->manager->assignPlan($request->user(), $managedUser, $plan, (int) ($valid['cycles'] ?? 1), $valid['reason'] ?? null);

        return $this->user($request, $managedUser, __(':user is on :plan now.', ['user' => $managedUser->username, 'plan' => $plan->name]));
    }

    public function endPlan(Request $request, User $managedUser)
    {
        $this->manager->authorize($request->user(), 'plan', $managedUser);

        $valid = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $this->manager->endPlan($request->user(), $managedUser, $valid['reason'] ?? null);

        return $this->user($request, $managedUser, 'The account is back on the default plan.');
    }

    /**
     * Add to the creator's balance, or take from it with a negative amount.
     */
    public function funds(Request $request, User $managedUser)
    {
        $this->manager->authorize($request->user(), 'funds', $managedUser);

        $valid = $request->validate([
            'amount' => ['required', 'numeric', 'between:-99999999,99999999'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $this->manager->adjustFunds($request->user(), $managedUser, (float) $valid['amount'], $valid['reason']);

        return $this->user($request, $managedUser, 'The balance was updated.');
    }

    /**
     * Grant AI credits, or take them back with a negative number.
     */
    public function credits(Request $request, User $managedUser)
    {
        $this->manager->authorize($request->user(), 'credits', $managedUser);

        $valid = $request->validate([
            'credits' => ['required', 'integer', 'between:-1000000,1000000'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $this->manager->adjustCredits($request->user(), $managedUser, (int) $valid['credits'], $valid['reason']);

        return $this->user($request, $managedUser, 'The AI credits were updated.');
    }

    /**
     * What admins have done to the account, newest first.
     */
    public function activity(Request $request, User $managedUser)
    {
        $actions = $managedUser
            ->adminActions()
            ->with('admin')
            ->paginate($request->perPage(15, 50));

        return (new AdminActionCollection($actions))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Payments the user made, or what they earned from others.
     */
    public function transactions(Request $request, User $managedUser)
    {
        $valid = $request->validate([
            'side' => ['nullable', Rule::in(['payments', 'earnings'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = ($valid['side'] ?? 'payments') === 'earnings'
            ? $managedUser->earnings()->with('user')->latest('paid_at')->latest('id')
            : $managedUser->transactions()->latest('id');

        $transactions = $query
            ->with('transactable')
            ->paginate($request->perPage(15, 50));

        return (new TransactionCollection($transactions))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function user(Request $request, User $user, string $message, array $extra = [])
    {
        $user
            ->refresh()
            ->load(['roles', 'subscriptions' => fn($q) => $q->active()->with('planModel')])
            ->loadCount('releases');

        $can = $this->manager->abilities($request->user(), $user);

        return (new AdminUserResource($user))
            ->additional([
                'can' => $can,
                'roles' => config('permission-defs.roles', []),
                ...$extra,
                'message' => $message,
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }
}
