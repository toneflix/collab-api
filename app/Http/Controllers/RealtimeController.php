<?php

namespace App\Http\Controllers;

use App\Broadcasting\Channels;
use App\Enums\HttpStatus;
use App\Http\Resources\ActionResource;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Providers\RealtimeServiceProvider;
use App\Services\Realtime\Realtime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

class RealtimeController extends Controller
{
    /**
     * Authorize the signed-in user to listen on a private channel.
     */
    public function auth(Request $request)
    {
        abort_unless(Realtime::enabled(), HttpStatus::NOT_FOUND->value, 'Realtime is turned off.');

        $request->validate(['channel_name' => ['required', 'string', 'max:190']]);

        RealtimeServiceProvider::channels();

        return Broadcast::auth($request);
    }

    /**
     * Share unsaved editor changes with everyone else on the track. Nothing is
     * stored; the sender saves as usual.
     */
    public function live(Request $request, Project $project, ProjectTrack $track)
    {
        $this->authorize('update', $track);

        $request->validate([
            'stems' => ['required', 'array', 'max:50'],
            'stems.*' => ['array'],
            'stems.*.clips' => ['nullable', 'array', 'max:100'],
        ]);

        // validate() would keep only the listed keys, so read the stems whole.
        $input = (array) $request->input('stems');
        $known = $track->stems()
            ->whereIn('id', array_keys($input))
            ->pluck('id')
            ->map(fn($id) => (string) $id)
            ->all();

        $stems = collect($input)->only($known);

        // Keep within what every driver can carry in one message (Pusher: 10 KB).
        abort_if(
            strlen((string) json_encode($stems)) > 8000,
            HttpStatus::UNPROCESSABLE_ENTITY->value,
            'Too many changes to share at once.'
        );

        $sent = $stems->isNotEmpty() && Realtime::send(Channels::projectTrack($track), 'stems.updated', [
            'track_id' => $track->id,
            'stems' => $stems->all(),
        ]);

        $result = ['sent' => $sent];

        return (new ActionResource($result))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }
}
