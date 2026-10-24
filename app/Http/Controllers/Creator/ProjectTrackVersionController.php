<?php

namespace App\Http\Controllers\Creator;

use App\Broadcasting\Channels;
use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectTrackVersionResource;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackVersion;
use App\Services\Realtime\Realtime;
use App\Services\Studio\TrackHistory;
use Illuminate\Http\Request;

class ProjectTrackVersionController extends Controller
{
    /**
     * The track's versions, newest first.
     */
    public function index(Request $request, Project $project, ProjectTrack $track)
    {
        $this->ensureCanEdit($request, $project, $track);

        $limit = min(50, max(1, (int) $request->input('limit', 20)));

        $versions = $track->versions()
            ->with(['user', 'restoredFrom'])
            ->latest('id')
            ->paginate($limit);

        return ProjectTrackVersionResource::collection($versions)->additional([
            'currentId' => $track->versions()->max('id'),
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Put the track back the way it was in a version.
     */
    public function restore(Request $request, Project $project, ProjectTrack $track, ProjectTrackVersion $version)
    {
        $this->ensureCanEdit($request, $project, $track);

        abort_unless((int) $version->track_id === (int) $track->id, HttpStatus::NOT_FOUND->value, 'That version isn\'t part of this track.');
        abort_if($project->released, HttpStatus::FORBIDDEN->value, 'Released tracks can\'t be changed.');

        $result = TrackHistory::for($track)->restore($version, $request->user());

        abort_unless($result['version'], HttpStatus::UNPROCESSABLE_ENTITY->value, 'The track already matches that version.');

        Realtime::send(Channels::projectTrack($track), 'track.restored', [
            'track_id' => $track->id,
            'version_id' => $result['version']->id,
            'restored_from_id' => $version->id,
        ]);

        return (new ProjectTrackVersionResource($result['version']->load(['user', 'restoredFrom'])))->additional([
            'missing' => $result['missing'],
            'keptAudio' => $result['kept_audio'],
            'message' => 'Version restored.',
            'status' => 'success',
            'status_code' => HttpStatus::ACCEPTED,
        ])->response()->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    protected function ensureCanEdit(Request $request, Project $project, ProjectTrack $track): void
    {
        abort_unless(
            (int) $track->project_id === (int) $project->id && $project->canEdit($request->user()),
            HttpStatus::FORBIDDEN->value,
            'Only the owner and collaborators can see this track\'s history.'
        );
    }
}
