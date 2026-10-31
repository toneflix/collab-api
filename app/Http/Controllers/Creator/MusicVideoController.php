<?php

namespace App\Http\Controllers\Creator;

use App\Enums\Feature;
use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\MusicVideoCollection;
use App\Http\Resources\MusicVideoResource;
use App\Http\Resources\MusicVideoShotResource;
use App\Http\Resources\TrackVideoResource;
use App\Models\MusicVideo;
use App\Models\MusicVideoCharacter;
use App\Models\MusicVideoShot;
use App\Models\Track;
use App\Services\Ai\Video\MusicVideos;
use App\Services\Ai\Video\ShotList;
use App\Services\Ai\Video\VideoStorage;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AI music videos for a creator's released tracks: draw a storyboard, change
 * its shots, render it and watch the result.
 */
class MusicVideoController extends Controller
{
    /**
     * The person's music videos, newest first, for one track when asked,
     * with the video minutes left and whether their plan takes own photos.
     */
    public function index(Request $request, MusicVideos $musicVideos)
    {
        $valid = $request->validate([
            'track' => ['nullable', 'integer'],
        ]);

        $track = $valid['track'] ?? null;

        $videos = MusicVideo::query()
            ->with('track')
            ->where('user_id', $request->user()->id)
            ->when($track, fn($query) => $query->where('track_id', $track))
            ->latest('id')
            ->paginate($request->perPage(20));
        $minutesLeft = $musicVideos->minutesLeft($request->user());
        $ownPhotos = (bool) $request->user()->feature(Feature::VIDEO_OWN_PHOTOS);

        return (new MusicVideoCollection($videos))
            ->additional([
                'minutesLeft' => $minutesLeft,
                'ownPhotos' => $ownPhotos,
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Start a music video for a released track the person owns.
     */
    public function store(Request $request, MusicVideos $videos, Track $track)
    {
        abort_unless($track->release?->isOwnedBy($request->user()), HttpStatus::FORBIDDEN->value, 'You can only make videos for your own tracks.');

        $valid = $request->validate([
            'ratio' => ['nullable', 'string', Rule::in(MusicVideo::RATIOS)],
            'brief' => ['nullable', 'string', 'max:1000'],
            'photo' => ['nullable', 'prohibits:characters', 'image', 'max:10240', 'dimensions:min_width=512,min_height=512'],
            'characters' => ['nullable', 'array', 'max:' . MusicVideoCharacter::MAX],
            'characters.*.name' => ['required', 'string', 'max:80'],
            'characters.*.description' => ['nullable', 'string', 'max:600'],
            'characters.*.singer' => ['nullable', 'boolean'],
            'characters.*.sheet' => ['required', 'image', 'max:10240', 'dimensions:min_width=512,min_height=512'],
            'consent' => [Rule::excludeIf(! $request->hasFile('photo') && ! $request->filled('characters')), 'required', 'accepted'],
        ], [
            'consent.required' => 'Confirm the images are of you, or that you have the right to use them.',
            'consent.accepted' => 'Confirm the images are of you, or that you have the right to use them.',
        ]);

        $photo = $request->file('photo');
        $characters = collect($valid['characters'] ?? [])
            ->map(fn(array $character, int $i) => [...$character, 'sheet' => $request->file("characters.{$i}.sheet")])
            ->values()
            ->all();
        $video = $videos->create($request->user(), $track, $valid, $photo, $characters);

        $video
            ->refresh()
            ->load(['track', 'characters', 'shots']);

        return (new MusicVideoResource($video))
            ->additional([
                'message' => 'Planning your music video.',
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }

    /**
     * A music video with its storyboard and the video minutes left to render it.
     */
    public function show(Request $request, MusicVideos $musicVideos, MusicVideo $video)
    {
        $this->authorize('view', $video);

        $video->load(['track', 'characters', 'shots']);
        $minutesLeft = $musicVideos->minutesLeft($request->user());

        return (new MusicVideoResource($video))
            ->additional([
                'minutesLeft' => $minutesLeft,
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Change what a shot shows before rendering.
     */
    public function updateShot(Request $request, MusicVideos $videos, MusicVideo $video, MusicVideoShot $shot)
    {
        $this->authorize('update', $video);
        abort_unless((int) $shot->music_video_id === (int) $video->id, HttpStatus::NOT_FOUND->value);

        $valid = $request->validate([
            'prompt' => ['required', 'string', 'min:3', 'max:600'],
        ]);

        $shot = $videos->updateShot($video, $shot, trim($valid['prompt']));

        return (new MusicVideoShotResource($shot))
            ->additional([
                'message' => 'Shot updated.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Put the storyboard's shots in a new order.
     */
    public function reorderShots(Request $request, MusicVideos $videos, MusicVideo $video)
    {
        $this->authorize('update', $video);

        $valid = $request->validate([
            'shots' => ['required', 'array', 'max:' . ShotList::MAX_SHOTS],
            'shots.*' => ['integer', 'distinct'],
        ]);

        $video = $videos->reorderShots($video, $valid['shots']);

        $video
            ->refresh()
            ->load(['track', 'characters', 'shots']);

        return (new MusicVideoResource($video))
            ->additional([
                'message' => 'Shots reordered.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * The track's finished music video for the player, or an empty object
     * when it has none.
     */
    public function track(MusicVideos $videos, Track $track)
    {
        $video = $videos->forTrack($track);

        $video?->load(['shots' => fn($query) => $query
            ->orderBy('position')
            ->limit(1)]);

        return (new TrackVideoResource($video))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Render the storyboard into the finished video.
     */
    public function render(MusicVideos $videos, MusicVideo $video)
    {
        $this->authorize('update', $video);

        $video = $videos->render($video);

        $video
            ->refresh()
            ->load(['track', 'characters', 'shots']);

        return (new MusicVideoResource($video))
            ->additional([
                'message' => 'Rendering your music video.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Draw a failed storyboard again, keeping the stills already drawn.
     */
    public function retryStoryboard(MusicVideos $videos, MusicVideo $video)
    {
        $this->authorize('update', $video);

        $video = $videos->retryStoryboard($video);

        $video
            ->refresh()
            ->load(['track', 'characters', 'shots']);

        return (new MusicVideoResource($video))
            ->additional([
                'message' => 'Drawing the rest of your storyboard.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    public function destroy(MusicVideos $videos, MusicVideo $video)
    {
        $this->authorize('delete', $video);

        $videos->delete($video);

        return (new MusicVideoResource($video))
            ->additional([
                'message' => 'Music video deleted.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Stream the finished video from a signed link.
     */
    public function file(VideoStorage $storage, MusicVideo $video)
    {
        return $storage->stream($video->file, 'video/mp4');
    }

    /**
     * Show a shot's still from a signed link.
     */
    public function keyframe(VideoStorage $storage, MusicVideo $video, MusicVideoShot $shot)
    {
        abort_unless((int) $shot->music_video_id === (int) $video->id, HttpStatus::NOT_FOUND->value);

        return $storage->stream($shot->keyframe, 'image/jpeg');
    }

    /**
     * Show a character sheet from a signed link.
     */
    public function sheet(VideoStorage $storage, MusicVideo $video, MusicVideoCharacter $character)
    {
        abort_unless((int) $character->music_video_id === (int) $video->id, HttpStatus::NOT_FOUND->value);

        return $storage->stream($character->sheet, 'image/jpeg');
    }
}
