<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\CanBeTakenDown;
use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiReviewResource;
use App\Http\Resources\ArtistClaimResource;
use App\Http\Resources\ModerationItemResource;
use App\Http\Resources\ReleaseReviewResource;
use App\Http\Resources\TakedownResource;
use App\Models\AiReview;
use App\Models\ArtistClaim;
use App\Models\Release;
use App\Models\Safety;
use App\Models\Takedown;
use App\Services\Artists\ArtistClaims;
use App\Services\Moderation\Ai\AiModerator;
use App\Services\Moderation\Moderation;
use App\Services\Moderation\ModerationQueue;
use App\Services\Moderation\Reportables;
use App\Services\Releases\ReleaseVersions;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
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

        $items = $this->queue->pending($valid, (int) ($valid['limit'] ?? 20));

        return $this->list(ModerationItemResource::collection($items));
    }

    public function takedowns(Request $request)
    {
        $valid = $request->validate([
            'status' => ['nullable', Rule::in([Takedown::ACTIVE, Takedown::APPEALED, Takedown::RESTORED])],
            'search' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $takedowns = $this->queue->takedowns($valid['status'] ?? null, $valid['search'] ?? null, (int) ($valid['limit'] ?? 20));

        return $this->list(TakedownResource::collection($takedowns));
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

        $releases = $this->queue->releases($valid['search'] ?? null, (int) ($valid['limit'] ?? 20));

        return $this->list(ReleaseReviewResource::collection($releases));
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

    /**
     * Claims on artists no account manages yet.
     */
    public function claims(Request $request, ArtistClaims $claims)
    {
        $valid = $request->validate([
            'status' => ['nullable', Rule::in([ArtistClaim::PENDING, ArtistClaim::APPROVED, ArtistClaim::REJECTED])],
            'search' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $list = $claims->queue($valid['status'] ?? ArtistClaim::PENDING, $valid['search'] ?? null, (int) ($valid['limit'] ?? 20));

        return $this->list(ArtistClaimResource::collection($list));
    }

    public function approveClaim(Request $request, ArtistClaim $claim, ArtistClaims $claims)
    {
        $valid = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $claims->approve($claim, $request->user(), $valid['note'] ?? null);

        return Providers::response()->success([
            'data' => ['id' => $claim->id],
            'message' => 'Approved. The artist is now linked to the claimant\'s account.',
        ], HttpStatus::ACCEPTED);
    }

    public function rejectClaim(Request $request, ArtistClaim $claim, ArtistClaims $claims)
    {
        $valid = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        $claims->reject($claim, $request->user(), $valid['note']);

        return Providers::response()->success([
            'data' => ['id' => $claim->id],
            'message' => 'Declined. The claimant was told why.',
        ], HttpStatus::ACCEPTED);
    }

    /**
     * Hand a claim to the AI moderator, which decides it.
     */
    public function aiReviewClaim(Request $request, ArtistClaim $claim, AiModerator $moderator)
    {
        return $this->sentToAi($moderator->send($claim, $request->user()));
    }

    /**
     * Hand a release waiting for approval to the AI moderator, which decides it.
     */
    public function aiReviewRelease(Request $request, Release $reviewRelease, AiModerator $moderator)
    {
        return $this->sentToAi($moderator->send($reviewRelease, $request->user()));
    }

    protected function sentToAi(AiReview $review)
    {
        return $this->done(new AiReviewResource($review->refresh()), 'Sent to the AI moderator. Its decision shows here when it is done.', HttpStatus::ACCEPTED);
    }

    public function claimDocument(ArtistClaim $claim, ArtistClaims $claims)
    {
        return $claims->downloadDocument($claim);
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

        return $this->done(new TakedownResource($takedown->fresh()), 'Taken down. The owner and reporters were told.', HttpStatus::CREATED);
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

        return $this->done(new TakedownResource($takedown->fresh('moderator')), 'Restored. The owner was told.', HttpStatus::ACCEPTED);
    }

    public function uphold(Request $request, Takedown $takedown)
    {
        $valid = $request->validate(['resolution' => ['nullable', 'string', 'max:2000']]);

        $this->moderation->uphold($takedown, $request->user(), $valid['resolution'] ?? null);

        return $this->done(new TakedownResource($takedown->fresh('moderator')), 'Appeal declined. The owner was told.', HttpStatus::ACCEPTED);
    }

    protected function item(string $type, int $id): Model
    {
        abort_unless(in_array($type, $this->reportables->names(), true), HttpStatus::NOT_FOUND->value);

        $item = $this->reportables->find($type, $id);
        abort_unless($item, HttpStatus::NOT_FOUND->value, 'That content no longer exists.');

        return $item;
    }

    /**
     * A page of a moderation list, with the counts the tabs show.
     */
    protected function list(AnonymousResourceCollection $list)
    {
        return $list->additional([
            'stats' => [
                ...$this->queue->stats(),
                'aiModerator' => app(AiModerator::class)->available(),
            ],
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    protected function done(JsonResource $resource, string $message, HttpStatus $status)
    {
        return $resource->additional([
            'message' => $message,
            'status' => 'success',
            'status_code' => $status,
        ])->response()->setStatusCode($status->value);
    }
}
