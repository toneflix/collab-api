<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\MusicVideoShortCollection;
use App\Http\Resources\MusicVideoShortResource;
use App\Models\MusicVideo;
use App\Models\MusicVideoShort;
use App\Services\Ai\Video\VideoShorts;
use App\Services\Ai\Video\VideoStorage;
use Illuminate\Http\Request;

/**
 * Vertical shorts cut from a creator's finished music video.
 */
class MusicVideoShortController extends Controller
{
    /**
     * The video's shorts, newest first.
     */
    public function index(Request $request, MusicVideo $video)
    {
        $this->authorize('view', $video);

        $shorts = $video
            ->shorts()
            ->with('video.track')
            ->latest('id')
            ->paginate($request->perPage(20, MusicVideoShort::MAX));

        return (new MusicVideoShortCollection($shorts))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Start cutting a short from a stretch of the finished video.
     */
    public function store(Request $request, VideoShorts $shorts, MusicVideo $video)
    {
        $this->authorize('update', $video);

        $valid = $request->validate([
            'start' => ['required', 'numeric', 'min:0'],
            'end' => ['required', 'numeric', 'gt:start'],
            'focus' => ['nullable', 'numeric', 'between:0,1'],
        ]);

        $short = $shorts->create($video, (float) $valid['start'], (float) $valid['end'], (float) ($valid['focus'] ?? 0.5));

        $short->load('video.track');

        return (new MusicVideoShortResource($short))
            ->additional([
                'message' => $short->status === MusicVideoShort::READY ? 'Short ready.' : 'Cutting your short.',
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }

    /**
     * A short, to check on one being cut.
     */
    public function show(MusicVideo $video, MusicVideoShort $short)
    {
        $this->authorize('view', $video);
        abort_unless((int) $short->music_video_id === (int) $video->id, HttpStatus::NOT_FOUND->value);

        $short->load('video.track');

        return (new MusicVideoShortResource($short))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    public function destroy(VideoShorts $shorts, MusicVideo $video, MusicVideoShort $short)
    {
        $this->authorize('delete', $video);
        abort_unless((int) $short->music_video_id === (int) $video->id, HttpStatus::NOT_FOUND->value);

        $shorts->delete($short);

        return (new MusicVideoShortResource($short))
            ->additional([
                'message' => 'Short deleted.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Stream a short from a signed link, as a file to save when asked.
     */
    public function file(Request $request, VideoStorage $storage, MusicVideo $video, MusicVideoShort $short)
    {
        abort_unless((int) $short->music_video_id === (int) $video->id, HttpStatus::NOT_FOUND->value);

        $download = $request->boolean('download') ? $storage->shortName($short) : null;

        return $storage->stream($short->file, 'video/mp4', $download);
    }
}
