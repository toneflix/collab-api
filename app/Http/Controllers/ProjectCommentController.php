<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Resources\CommentCollection;
use App\Http\Resources\CommentResource;
use App\Models\Project;
use BeyondCode\Comments\Comment;
use Illuminate\Http\Request;

class ProjectCommentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Project $project, string $comment = null)
    {
        if ($request->has('track_id')) {
            $project = $project->tracks()->findOrFail($request->track_id);
        }

        if ($comment) {
            /** @var \Illuminate\Database\Eloquent\Builder $query */
            $query = $project->comments()->findOrFail($comment)->comments();
        } else {
            /** @var \Illuminate\Database\Eloquent\Builder $query */
            $query = $project->comments();
        }

        $projects = $query->paginate($request->input('limit', 45));

        return (new CommentCollection($projects))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Project $project)
    {
        $this->validate($request, [
            'comment' => 'required|string',
        ]);

        if ($request->has('track_id')) {
            $project = $project->tracks()->findOrFail($request->track_id);
        }

        $comment = $project->comment($request->comment);

        return (new CommentResource($comment))->additional([
            'message' => 'Comment posted.',
            'status' => 'success',
            'status_code' => HttpStatus::CREATED,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Project $project, string $comment)
    {
        return $this->index($request, $project, $comment);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Project $project, Comment $comment)
    {
        $this->validate($request, [
            'comment' => 'required|string',
        ]);

        $comment = $comment->comment($request->comment);

        return (new CommentResource($comment))->additional([
            'message' => 'Reply sent.',
            'status' => 'success',
            'status_code' => HttpStatus::CREATED,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Project $project, Comment $comment)
    {
        $comment->delete();

        Providers::response()->success([
            'message' => 'Comment Deleted Successfully'
        ]);
    }
}