<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\QueueAgentRun;
use App\Http\Resources\AgentRunCollection;
use App\Http\Resources\AgentRunResource;
use App\Models\AgentRun;
use App\Services\Agent\AgentPlanner;
use App\Services\Agent\ContentAgent;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The content agent for the users of an agent account: their account's runs
 * and new prompts for it.
 */
class ContentAgentController extends Controller
{
    public function __construct(protected ContentAgent $agent)
    {
        parent::__construct();
    }

    public function index(Request $request, AgentPlanner $planner)
    {
        $this->authorize('own', AgentRun::class);

        $valid = $request->validate([
            'status' => ['nullable', 'string', Rule::in(array_keys(AgentRun::VIEWS))],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $runs = AgentRun::query()
            ->forList()
            ->inView($valid['status'] ?? null)
            ->where('owner_id', $request->user()->id)
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
        $this->authorize('own', AgentRun::class);

        $user = $request->user();

        $run = $this->agent->queue($user, $user, $request->validated('prompt'), $request->options());

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
        $this->authorize('view', $run);

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
        $this->authorize('update', $run);

        $run = $this->agent->cancel($run);

        return $this->changed($run, 'Run cancelled.');
    }

    public function retry(AgentRun $run)
    {
        $this->authorize('update', $run);

        $run = $this->agent->retry($run);

        return $this->changed($run, 'Run queued again.');
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
