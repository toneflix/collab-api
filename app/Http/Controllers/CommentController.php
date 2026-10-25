<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Http\Resources\CommentCollection;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Release;
use App\Models\Track;
use App\Services\Conversations\Conversations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, string $pid, ?string $comment_id = null)
    {
        [$commentable, $comment] = $this->resolver($request, $pid, $comment_id);

        if ($comment) {
            /** @var Builder $query */
            $query = $comment->comments();
        } else {
            /** @var Builder $query */
            $query = $commentable->comments();
        }

        $comments = $query->paginate($request->perPage(45));

        return (new CommentCollection($comments))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, string $pid)
    {
        $this->validate($request, [
            'comment' => 'required|string',
        ]);

        [$commentable] = $this->resolver($request, $pid);

        $comment = $commentable->comment($request->comment);

        Conversations::posted($comment);

        return (new CommentResource($comment))
            ->additional([
                'message' => 'Comment posted.',
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $pid, string $comment_id)
    {
        return $this->index($request, $pid, $comment_id);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $pid, string $comment_id)
    {
        $this->validate($request, [
            'comment' => 'required|string',
        ]);

        [$commentable, $comment] = $this->resolver($request, $pid, $comment_id);

        $comment = $comment->comment($request->comment);

        Conversations::posted($comment);

        return (new CommentResource($comment))
            ->additional([
                'message' => 'Reply sent.',
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $pid, string $comment_id)
    {
        [$commentable, $comment] = $this->resolver($request, $pid, $comment_id);

        Conversations::broadcast($comment, 'comment.deleted');

        $comment->delete();

        return (new CommentResource($comment))
            ->additional([
                'message' => 'Comment Deleted Successfully',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Resolve the comment interface
     *
     * @return array{
     *  0:Project|Track|Release,
     *  1:Comment|null
     * }
     */
    public function resolver(Request $request, string $commentable_id, ?string $comment_id = null)
    {
        // Guess class name
        $className = str($request->route()->getAction('prefix'))
            ->after('{')
            ->before('}')
            ->studly()
            ->prepend('\\App\\Models\\')
            ->toString();

        /** @var Project|Track|Release $commentable */
        $commentable = (new $className)->resolveRouteBinding($commentable_id);

        if ($request->has('track_id') && method_exists($commentable, 'tracks')) {
            /** @var Track $commentable */
            $commentable = $commentable
                ->tracks()
                ->findOrFail($request->track_id);
        }

        $comment = null;

        if ($commentable) {
            if ($comment_id) {
                /** @var Comment $comment */
                $comment = $commentable
                    ->comments()
                    ->findOrFail($comment_id);
            }
        }

        return [$commentable, $comment];
    }
}
