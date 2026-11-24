<?php

namespace App\Http\Controllers\Creator;

use App\Enums\Feature;
use App\Enums\HttpStatus;
use App\Enums\VideoQuality;
use App\Http\Controllers\Controller;
use App\Http\Resources\MusicVideoCollection;
use App\Http\Resources\MusicVideoResource;
use App\Http\Resources\MusicVideoShotResource;
use App\Http\Resources\TrackVideoResource;
use App\Models\MusicVideo;
use App\Models\MusicVideoCharacter;
use App\Models\MusicVideoShot;
use App\Models\MusicVideoTake;
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
     * with the video minutes left and whether their plan takes own photos
     * and advanced quality.
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
        $secondsLeft = $musicVideos->secondsLeft($request->user());
        $minutesLeft = intdiv($secondsLeft, 60);
        $ownPhotos = (bool) $request->user()->feature(Feature::VIDEO_OWN_PHOTOS);
        $advanced = (bool) $request->user()->feature(Feature::VIDEO_ADVANCED);

        return (new MusicVideoCollection($videos))
            ->additional([
                'minutesLeft' => $minutesLeft,
                'secondsLeft' => $secondsLeft,
                'ownPhotos' => $ownPhotos,
                'advanced' => $advanced,
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
            'mode' => ['nullable', 'string', Rule::in(MusicVideo::MODES)],
            'quality' => ['nullable', Rule::enum(VideoQuality::class)],
            'brief' => ['nullable', 'string', 'max:1000'],
            'photo' => ['nullable', 'prohibits:characters', 'image', 'max:10240', 'dimensions:min_width=512,min_height=512'],
            'characters' => ['nullable', 'array', 'max:' . MusicVideoCharacter::MAX],
            'characters.*.name' => ['required', 'string', 'max:80'],
            'characters.*.description' => ['nullable', 'string', 'max:600'],
            'characters.*.singer' => ['nullable', 'boolean'],
            'characters.*.sheet' => ['required', 'image', 'max:10240', 'dimensions:min_width=512,min_height=512'],
            'saved' => ['nullable', 'array', 'max:' . MusicVideoCharacter::MAX],
            'saved.*.id' => ['required', 'integer', 'distinct'],
            'saved.*.singer' => ['nullable', 'boolean'],
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
        $saved = $valid['saved'] ?? [];
        $video = $videos->create($request->user(), $track, $valid, $photo, $characters, $saved);

        $video
            ->refresh()
            ->load(['track', 'characters', 'shots.take', 'takes']);

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
     * A music video with its storyboard, the video time left to render it and
     * whether the plan takes the creator's own clips.
     */
    public function show(Request $request, MusicVideos $musicVideos, MusicVideo $video)
    {
        $this->authorize('view', $video);

        $video->load(['track', 'characters', 'shots.take', 'takes']);
        $secondsLeft = $musicVideos->secondsLeft($request->user());
        $minutesLeft = intdiv($secondsLeft, 60);
        $ownPhotos = (bool) $request->user()->feature(Feature::VIDEO_OWN_PHOTOS);

        return (new MusicVideoResource($video))
            ->additional([
                'minutesLeft' => $minutesLeft,
                'secondsLeft' => $secondsLeft,
                'ownPhotos' => $ownPhotos,
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Change what a shot shows.
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
     * Draw a new still for a shot from its prompt.
     */
    public function redrawStill(MusicVideos $videos, MusicVideo $video, MusicVideoShot $shot)
    {
        $this->authorize('update', $video);
        abort_unless((int) $shot->music_video_id === (int) $video->id, HttpStatus::NOT_FOUND->value);

        $shot = $videos->redrawStill($video, $shot);

        return (new MusicVideoShotResource($shot))
            ->additional([
                'message' => 'Drawing a new still.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Use the creator's own clip as a take of a shot, or their image as its still.
     */
    public function addTake(Request $request, MusicVideos $videos, MusicVideo $video, MusicVideoShot $shot)
    {
        $this->authorize('update', $video);
        abort_unless((int) $shot->music_video_id === (int) $video->id, HttpStatus::NOT_FOUND->value);

        $request->validate([
            'file' => ['required', 'file', 'max:' . 100 * 1024, 'mimetypes:video/mp4,video/quicktime,video/webm,image/jpeg,image/png,image/webp'],
            'consent' => ['required', 'accepted'],
        ], [
            'consent.required' => 'Confirm the clip is yours, or that you have the right to use it.',
            'consent.accepted' => 'Confirm the clip is yours, or that you have the right to use it.',
        ]);

        $file = $request->file('file');
        $videos->addOwnTake($video, $shot, $file);

        $video->load(['track', 'characters', 'shots.take', 'takes']);

        return (new MusicVideoResource($video))
            ->additional([
                'message' => 'Added to the shot.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Save the editor's strip of shots: order, trims, splits, merges, the
     * take each plays or the motion over its still.
     */
    public function saveShots(Request $request, MusicVideos $videos, MusicVideo $video)
    {
        $this->authorize('update', $video);

        $valid = $request->validate([
            'shots' => ['required', 'array', 'min:1', 'max:' . ShotList::MAX_SHOTS],
            'shots.*.id' => ['nullable', 'integer', 'required_without:shots.*.copy_of'],
            'shots.*.copy_of' => ['nullable', 'integer'],
            'shots.*.start' => ['required', 'numeric', 'min:0'],
            'shots.*.end' => ['required', 'numeric', 'min:0'],
            'shots.*.take_id' => ['nullable', 'integer'],
            'shots.*.offset' => ['nullable', 'numeric', 'min:0'],
            'shots.*.motion' => ['nullable', 'string', Rule::in(MusicVideoShot::MOTIONS)],
        ]);

        $shots = $valid['shots'];

        // Validated rows come back keyed in the order rules matched them
        ksort($shots);

        $video = $videos->saveShots($video, array_values($shots));

        $video
            ->refresh()
            ->load(['track', 'characters', 'shots.take', 'takes']);

        return (new MusicVideoResource($video))
            ->additional([
                'message' => 'Shots saved.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Put the shots back as the finished video has them.
     */
    public function discardEdits(MusicVideos $videos, MusicVideo $video)
    {
        $this->authorize('update', $video);

        $video = $videos->discardEdits($video);

        $video
            ->refresh()
            ->load(['track', 'characters', 'shots.take', 'takes']);

        return (new MusicVideoResource($video))
            ->additional([
                'message' => 'Changes discarded.',
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
     * Save the video: render the shots that need new clips and stitch it.
     */
    public function render(MusicVideos $videos, MusicVideo $video)
    {
        $this->authorize('update', $video);

        $video = $videos->render($video);

        $video
            ->refresh()
            ->load(['track', 'characters', 'shots.take', 'takes']);

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
            ->load(['track', 'characters', 'shots.take', 'takes']);

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
     * Stream a shot's take from a signed link.
     */
    public function take(VideoStorage $storage, MusicVideo $video, MusicVideoTake $take)
    {
        abort_unless((int) $take->music_video_id === (int) $video->id, HttpStatus::NOT_FOUND->value);

        return $storage->stream($take->file, 'video/mp4');
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
