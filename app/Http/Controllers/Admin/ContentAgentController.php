<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\QueueAgentRun;
use App\Http\Resources\AgentOwnerCollection;
use App\Http\Resources\AgentOwnerResource;
use App\Http\Resources\AgentRunCollection;
use App\Http\Resources\AgentRunResource;
use App\Models\AgentRun;
use App\Models\User;
use App\Policies\AgentRunPolicy;
use App\Services\Admin\UserDirectory;
use App\Services\Admin\UserManager;
use App\Services\Agent\AgentPlanner;
use App\Services\Agent\ContentAgent;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lets admins pick the agent accounts, queue prompts for them and follow
 * every run.
 */
class ContentAgentController extends Controller
{
    public function __construct(protected ContentAgent $agent)
    {
        parent::__construct();

        $this->middleware(function (Request $request, Closure $next) {
            abort_unless(
                AgentRunPolicy::manages($request->user()),
                HttpStatus::FORBIDDEN->value,
                'You do not have permission to run AI Studio.'
            );

            return $next($request);
        });
    }

    public function index(Request $request, AgentPlanner $planner)
    {
        $valid = $request->validate([
            'status' => ['nullable', 'string', Rule::in(array_keys(AgentRun::VIEWS))],
            'owner_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $runs = AgentRun::query()
            ->forList()
            ->inView($valid['status'] ?? null)
            ->when($valid['owner_id'] ?? null, fn(Builder $q, int $owner) => $q->where('owner_id', $owner))
            ->latest('id')
            ->paginate($request->perPage(20, 50));

        return (new AgentRunCollection($runs))
            ->additional([
                'agent' => $planner->clientConfig(),
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    public function store(QueueAgentRun $request)
    {
        $request->validate(['owner_id' => ['required']]);

        $owner = User::query()
            ->withoutGlobalScopes()
            ->findOrFail($request->validated('owner_id'));

        $run = $this->agent->queue($request->user(), $owner, $request->validated('prompt'), $request->options());

        $run->load(['owner', 'user', 'steps']);

        return (new AgentRunResource($run))
            ->additional([
                'message' => 'Prompt queued.',
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }

    public function show(AgentRun $run)
    {
        $run->loadForPage();

        return (new AgentRunResource($run))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    public function cancel(AgentRun $run)
    {
        $run = $this->agent->cancel($run);

        return $this->changed($run, 'Run stopped.');
    }

    public function removePart(Request $request, AgentRun $run)
    {
        $valid = $request->validate(['part' => ['required', 'string', 'max:100']]);

        $run = $this->agent->remove($run, $valid['part']);

        return $this->changed($run, 'Removed from the run.');
    }

    public function retry(AgentRun $run)
    {
        $run = $this->agent->retry($run);

        return $this->changed($run, 'Run queued again.');
    }

    /**
     * Accounts to pick from, with their AI access and credits: the agent
     * accounts, or with `all` every account, to add one.
     */
    public function owners(Request $request, UserDirectory $directory)
    {
        $valid = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'all' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $users = $directory
            ->query(['search' => $valid['search'] ?? null, 'sort' => 'name'])
            ->when(! $request->boolean('all'), fn(Builder $q) => $q->where('content_agent', true))
            ->paginate($request->perPage(10, 20));

        return (new AgentOwnerCollection($users))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Make an account an agent account, or stop it being one.
     */
    public function account(Request $request, UserManager $manager, User $managedUser)
    {
        $valid = $request->validate(['enabled' => ['required', 'boolean']]);

        $user = $manager->setContentAgent($request->user(), $managedUser, (bool) $valid['enabled']);

        return (new AgentOwnerResource($user))
            ->additional([
                'message' => $user->content_agent ? 'Agent account added.' : 'Agent account removed.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    protected function changed(AgentRun $run, string $message)
    {
        $run->loadForPage();

        return (new AgentRunResource($run))
            ->additional([
                'message' => $message,
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
