<?php

namespace App\Http\Controllers\Creator;

use App\Enums\Feature;
use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\VideoCharacterCollection;
use App\Http\Resources\VideoCharacterResource;
use App\Models\MusicVideo;
use App\Models\MusicVideoCharacter;
use App\Models\VideoCharacter;
use App\Services\Ai\Video\VideoCharacters;
use App\Services\Ai\Video\VideoStorage;
use Illuminate\Http\Request;

/**
 * A creator's saved music video characters, to cast in any of their videos.
 */
class VideoCharacterController extends Controller
{
    /**
     * The person's saved characters, newest first, and whether their plan
     * takes their own sheets.
     */
    public function index(Request $request)
    {
        $characters = VideoCharacter::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->paginate($request->perPage(40, VideoCharacter::MAX));
        $ownPhotos = (bool) $request->user()->feature(Feature::VIDEO_OWN_PHOTOS);

        return (new VideoCharacterCollection($characters))
            ->additional([
                'ownPhotos' => $ownPhotos,
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Save the person's own character sheet.
     */
    public function store(Request $request, VideoCharacters $characters)
    {
        $valid = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:600'],
            'sheet' => ['required', 'image', 'max:10240', 'dimensions:min_width=512,min_height=512'],
            'consent' => ['required', 'accepted'],
        ], [
            'consent.required' => 'Confirm the image is of you or someone who agreed, or that you have the right to use it.',
            'consent.accepted' => 'Confirm the image is of you or someone who agreed, or that you have the right to use it.',
        ]);

        $sheet = $request->file('sheet');
        $character = $characters->upload($request->user(), $valid['name'], $valid['description'] ?? null, $sheet);

        return (new VideoCharacterResource($character))
            ->additional([
                'message' => 'Character saved.',
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }

    /**
     * Save a character from one of the person's videos.
     */
    public function keep(VideoCharacters $characters, MusicVideo $video, MusicVideoCharacter $character)
    {
        $this->authorize('update', $video);
        abort_unless((int) $character->music_video_id === (int) $video->id, HttpStatus::NOT_FOUND->value);

        $saved = $characters->keep($character);

        return (new VideoCharacterResource($saved))
            ->additional([
                'message' => 'Saved to your characters.',
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }

    /**
     * Rename a saved character or change who they are.
     */
    public function update(Request $request, VideoCharacter $character)
    {
        $this->authorize('update', $character);

        $valid = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:600'],
        ]);

        $character
            ->fill(['name' => $valid['name'], 'description' => $valid['description'] ?? ''])
            ->save();

        return (new VideoCharacterResource($character))
            ->additional([
                'message' => 'Character updated.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    public function destroy(VideoCharacters $characters, VideoCharacter $character)
    {
        $this->authorize('delete', $character);

        $characters->delete($character);

        return (new VideoCharacterResource($character))
            ->additional([
                'message' => 'Character removed.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Show a saved character's sheet from a signed link.
     */
    public function sheet(VideoStorage $storage, VideoCharacter $character)
    {
        return $storage->stream($character->sheet, 'image/jpeg');
    }
}
