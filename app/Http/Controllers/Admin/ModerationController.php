<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\CanBeTakenDown;
use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReleaseResource;
use App\Http\Resources\TakedownResource;
use App\Models\Release;
use App\Models\Safety;
use App\Models\Takedown;
use App\Services\Moderation\Moderation;
use App\Services\Moderation\ModerationQueue;
use App\Services\Moderation\Reportables;
use App\Services\Releases\ReleaseVersions;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The moderation queue: reported and flagged content, takedowns and appeals.
 */
class ModerationController extends Controller
{
    public function __construct(
        protected Moderation $moderation,
        protected ModerationQueue $queue,
        protected Reportables $reportables,
    ) {
        parent::__construct();

        $this->middleware(function (Request $request, Closure $next) {
            abort_unless(
                $request->user()?->checkPermissionTo('manage-content'),
                HttpStatus::FORBIDDEN->value,
                'You do not have permission to moderate content.'
            );

            return $next($request);
        });
    }

    /**
     * Content waiting for review.
     */
    public function queue(Request $request)
    {
        $valid = $request->validate([
            'type' => ['nullable', Rule::in($this->reportables->names())],
            'reason' => ['nullable', Rule::in(Safety::$allowedReasons)],
            'source' => ['nullable', Rule::in([Safety::TYPE_REPORT, Safety::TYPE_MATCH, Safety::TYPE_LYRICS])],
            'search' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ]);

        return $this->page($this->queue->pending($valid, (int) ($valid['limit'] ?? 20)));
    }

    public function takedowns(Request $request)
    {
        $valid = $request->validate([
            'status' => ['nullable', Rule::in([Takedown::ACTIVE, Takedown::APPEALED, Takedown::RESTORED])],
            'search' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $page = $this->queue->takedowns($valid['status'] ?? null, $valid['search'] ?? null, (int) ($valid['limit'] ?? 20));

        return $this->page($page, TakedownResource::collection($page->items())->resolve());
    }

    /**
     * Releases waiting for approval, when release reviews are on.
     */
    public function releases(Request $request)
    {
        $valid = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $page = $this->queue->releases($valid['search'] ?? null, (int) ($valid['limit'] ?? 20));

        return $this->page($page, collect($page->items())->map(fn (Release $release) => [
            ...(new ReleaseResource($release))->resolve($request),
            'owner' => $release->user ? ['id' => $release->user->id, 'name' => $release->user->fullname, 'username' => $release->user->username] : null,
            'versionOf' => $release->project?->title,
            'submittedAt' => $release->updated_at,
        ])->all());
    }

    public function approveRelease(Request $request, Release $reviewRelease, ReleaseVersions $versions)
    {
        abort_unless($reviewRelease->isInReview(), HttpStatus::UNPROCESSABLE_ENTITY->value, 'This release is not waiting for review.');

        $versions->approve($reviewRelease, $request->user());

        return Providers::response()->success([
            'data' => ['id' => $reviewRelease->id],
            'message' => 'Approved. The release is live and its owner was told.',
        ], HttpStatus::ACCEPTED);
    }

    public function rejectRelease(Request $request, Release $reviewRelease, ReleaseVersions $versions)
    {
        $valid = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        abort_unless($reviewRelease->isInReview(), HttpStatus::UNPROCESSABLE_ENTITY->value, 'This release is not waiting for review.');

        $versions->reject($reviewRelease, $request->user(), $valid['note']);

        return Providers::response()->success([
            'data' => ['id' => $reviewRelease->id],
            'message' => 'Sent back to its owner with your note.',
        ], HttpStatus::ACCEPTED);
    }

    public function takeDown(Request $request, string $type, int $id)
    {
        $valid = $request->validate([
            'reason' => ['required', Rule::in(Safety::$allowedReasons)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $item = $this->item($type, $id);
        abort_unless($item instanceof CanBeTakenDown, HttpStatus::UNPROCESSABLE_ENTITY->value, 'This kind of content can\'t be taken down.');

        $takedown = $this->moderation->takeDown($item, $request->user(), $valid['reason'], $valid['note'] ?? null);

        return Providers::response()->success([
            'data' => (new TakedownResource($takedown->fresh()))->resolve(),
            'message' => 'Taken down. The owner and reporters were told.',
        ], HttpStatus::CREATED);
    }

    public function dismiss(Request $request, string $type, int $id)
    {
        $closed = $this->moderation->dismiss($this->item($type, $id), $request->user());

        return Providers::response()->success([
            'data' => ['closed' => $closed],
            'message' => 'Reports dismissed.',
        ], HttpStatus::ACCEPTED);
    }

    public function resolve(Request $request, string $type, int $id)
    {
        $closed = $this->moderation->resolve($this->item($type, $id), $request->user());

        return Providers::response()->success([
            'data' => ['closed' => $closed],
            'message' => 'Reports marked as handled.',
        ], HttpStatus::ACCEPTED);
    }

    public function restore(Request $request, Takedown $takedown)
    {
        $valid = $request->validate(['resolution' => ['nullable', 'string', 'max:2000']]);

        $this->moderation->restore($takedown, $request->user(), $valid['resolution'] ?? null);

        return Providers::response()->success([
            'data' => (new TakedownResource($takedown->fresh('moderator')))->resolve(),
            'message' => 'Restored. The owner was told.',
        ], HttpStatus::ACCEPTED);
    }

    public function uphold(Request $request, Takedown $takedown)
    {
        $valid = $request->validate(['resolution' => ['nullable', 'string', 'max:2000']]);

        $this->moderation->uphold($takedown, $request->user(), $valid['resolution'] ?? null);

        return Providers::response()->success([
            'data' => (new TakedownResource($takedown->fresh('moderator')))->resolve(),
            'message' => 'Appeal declined. The owner was told.',
        ], HttpStatus::ACCEPTED);
    }

    protected function item(string $type, int $id): Model
    {
        abort_unless(in_array($type, $this->reportables->names(), true), HttpStatus::NOT_FOUND->value);

        $item = $this->reportables->find($type, $id);
        abort_unless($item, HttpStatus::NOT_FOUND->value, 'That content no longer exists.');

        return $item;
    }

    /**
     * @param  array<int, mixed>|null  $items
     */
    protected function page(LengthAwarePaginator $page, ?array $items = null)
    {
        return Providers::response()->success([
            'data' => $items ?? $page->items(),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                ...$this->queue->stats(),
            ],
            'message' => HttpStatus::message(HttpStatus::OK),
        ]);
    }
}
