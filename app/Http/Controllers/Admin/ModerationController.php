<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\CanBeTakenDown;
use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActionResource;
use App\Http\Resources\AiReviewResource;
use App\Http\Resources\ArtistClaimCollection;
use App\Http\Resources\ArtistClaimResource;
use App\Http\Resources\ModerationItemCollection;
use App\Http\Resources\ReleaseReviewCollection;
use App\Http\Resources\ReleaseReviewResource;
use App\Http\Resources\TakedownCollection;
use App\Http\Resources\TakedownResource;
use App\Models\AiReview;
use App\Models\ArtistClaim;
use App\Models\Release;
use App\Models\Safety;
use App\Models\Takedown;
use App\Models\Track;
use App\Services\Artists\ArtistClaims;
use App\Services\Moderation\Ai\AiModerator;
use App\Services\Moderation\Moderation;
use App\Services\Moderation\ModerationQueue;
use App\Services\Moderation\Reportables;
use App\Services\Releases\ReleaseVersions;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Toneflix\ResourceModifier\Services\Json\JsonResource;
use Toneflix\ResourceModifier\Services\Json\ResourceCollection;

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
                Moderation::allows($request->user()),
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
        $list = new ModerationItemCollection($items);

        return $this->list($list);
    }

    public function takedowns(Request $request)
    {
        $valid = $request->validate([
            'status' => ['nullable', Rule::in([Takedown::ACTIVE, Takedown::APPEALED, Takedown::RESTORED])],
            'search' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $takedowns = $this->queue->takedowns($valid['status'] ?? null, $valid['search'] ?? null, (int) ($valid['limit'] ?? 20));
        $list = new TakedownCollection($takedowns);

        return $this->list($list);
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
        $list = new ReleaseReviewCollection($releases);

        return $this->list($list);
    }

    public function approveRelease(Request $request, Release $reviewRelease, ReleaseVersions $versions)
    {
        abort_unless($reviewRelease->isInReview(), HttpStatus::UNPROCESSABLE_ENTITY->value, 'This release is not waiting for review.');

        $versions->approve($reviewRelease, $request->user());

        return $this->done(new ReleaseReviewResource($reviewRelease), 'Release approved.');
    }

    public function rejectRelease(Request $request, Release $reviewRelease, ReleaseVersions $versions)
    {
        $valid = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        abort_unless($reviewRelease->isInReview(), HttpStatus::UNPROCESSABLE_ENTITY->value, 'This release is not waiting for review.');

        $versions->reject($reviewRelease, $request->user(), $valid['note']);

        return $this->done(new ReleaseReviewResource($reviewRelease), 'Sent back to its owner.');
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

        $queue = $claims->queue($valid['status'] ?? ArtistClaim::PENDING, $valid['search'] ?? null, (int) ($valid['limit'] ?? 20));
        $list = new ArtistClaimCollection($queue);

        return $this->list($list);
    }

    public function approveClaim(Request $request, ArtistClaim $claim, ArtistClaims $claims)
    {
        $valid = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $claims->approve($claim, $request->user(), $valid['note'] ?? null);

        return $this->done(new ArtistClaimResource($claim), 'Claim approved.');
    }

    public function rejectClaim(Request $request, ArtistClaim $claim, ArtistClaims $claims)
    {
        $valid = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        $claims->reject($claim, $request->user(), $valid['note']);

        return $this->done(new ArtistClaimResource($claim), 'Claim declined.');
    }

    /**
     * Hand a claim to the AI moderator, which decides it.
     */
    public function aiReviewClaim(Request $request, ArtistClaim $claim, AiModerator $moderator)
    {
        $review = $moderator->send($claim, $request->user());

        return $this->sentToAi($review);
    }

    /**
     * Hand a release waiting for approval to the AI moderator, which decides it.
     */
    public function aiReviewRelease(Request $request, Release $reviewRelease, AiModerator $moderator)
    {
        $review = $moderator->send($reviewRelease, $request->user());

        return $this->sentToAi($review);
    }

    /**
     * Ask the AI moderator whether a direct upload is recorded music or agentic.
     */
    public function aiReviewTrack(Request $request, Track $rankTrack, AiModerator $moderator)
    {
        $review = $moderator->send($rankTrack, $request->user());

        return $this->sentToAi($review);
    }

    protected function sentToAi(AiReview $review)
    {
        $review->refresh();

        return $this->done(new AiReviewResource($review), 'Sent to the AI moderator.');
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
        $takedown->refresh();

        return $this->done(new TakedownResource($takedown), 'Taken down.', HttpStatus::CREATED);
    }

    /**
     * Delete content for good, after the moderator types its name and gives a
     * two-factor code.
     */
    public function destroy(Request $request, string $type, int $id)
    {
        $valid = $request->validate([
            'reason' => ['required', Rule::in(Safety::$allowedReasons)],
            'note' => ['nullable', 'string', 'max:2000'],
            'confirm_name' => ['required', 'string', 'max:200'],
            'code' => ['required', 'string', 'max:20'],
        ]);

        $item = $this->item($type, $id);

        $this->moderation->delete($item, $request->user(), $valid['reason'], $valid['note'] ?? null, $valid['confirm_name'], $valid['code']);

        $result = ['deleted' => true, 'type' => $type, 'id' => $id];

        return $this->done(new ActionResource($result), 'Deleted.');
    }

    public function dismiss(Request $request, string $type, int $id)
    {
        $item = $this->item($type, $id);
        $closed = $this->moderation->dismiss($item, $request->user());
        $result = ['closed' => $closed];

        return $this->done(new ActionResource($result), 'Reports dismissed.');
    }

    public function resolve(Request $request, string $type, int $id)
    {
        $item = $this->item($type, $id);
        $closed = $this->moderation->resolve($item, $request->user());
        $result = ['closed' => $closed];

        return $this->done(new ActionResource($result), 'Reports marked as handled.');
    }

    public function restore(Request $request, Takedown $takedown)
    {
        $valid = $request->validate(['resolution' => ['nullable', 'string', 'max:2000']]);

        $this->moderation->restore($takedown, $request->user(), $valid['resolution'] ?? null);

        $takedown
            ->refresh()
            ->load('moderator');

        return $this->done(new TakedownResource($takedown), 'Restored.');
    }

    public function uphold(Request $request, Takedown $takedown)
    {
        $valid = $request->validate(['resolution' => ['nullable', 'string', 'max:2000']]);

        $this->moderation->uphold($takedown, $request->user(), $valid['resolution'] ?? null);

        $takedown
            ->refresh()
            ->load('moderator');

        return $this->done(new TakedownResource($takedown), 'Appeal declined.');
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
    protected function list(ResourceCollection $list)
    {
        $stats = [
            ...$this->queue->stats(),
            'aiModerator' => app(AiModerator::class)->available(),
        ];

        return $list->additional([
            'stats' => $stats,
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    protected function done(JsonResource $resource, string $message, HttpStatus $status = HttpStatus::ACCEPTED)
    {
        return $resource
            ->additional([
                'message' => $message,
                'status' => 'success',
                'status_code' => $status,
            ])
            ->response()
            ->setStatusCode($status->value);
    }
}
