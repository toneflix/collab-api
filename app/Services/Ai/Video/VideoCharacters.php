<?php

namespace App\Services\Ai\Video;

use App\Enums\Feature;
use App\Models\MusicVideoCharacter;
use App\Models\User;
use App\Models\VideoCharacter;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * A creator's saved characters: kept from a video's cast or uploaded, and
 * cast in their later videos so the same people appear in each.
 */
class VideoCharacters
{
    public function __construct(
        protected VideoStorage $storage,
    ) {}

    /**
     * Save a character of one of the person's videos, or give back the saved
     * character it already is.
     */
    public function keep(MusicVideoCharacter $character): VideoCharacter
    {
        $saved = $character->savedAs;

        if ($saved) {
            return $saved;
        }

        if (! $character->sheet) {
            throw ValidationException::withMessages(['character' => 'Wait for this character\'s sheet to be drawn.']);
        }

        $user = $character->video->user;

        $this->ensureRoom($user);

        $sheet = $this->storage->putCharacter($user, $character->sheet, pathinfo($character->sheet, PATHINFO_EXTENSION) ?: 'jpg');

        $saved = VideoCharacter::query()
            ->create([
                'user_id' => $user->id,
                'name' => $character->name,
                'description' => $character->description,
                'sheet' => $sheet,
                'uploaded' => $character->provided,
            ]);

        $character
            ->savedAs()
            ->associate($saved)
            ->save();

        return $saved;
    }

    /**
     * Save the person's own character sheet, for plans that allow their own images.
     */
    public function upload(User $user, string $name, ?string $description, UploadedFile $sheet): VideoCharacter
    {
        if (! $user->feature(Feature::VIDEO_OWN_PHOTOS)) {
            throw ValidationException::withMessages(['sheet' => 'Your plan doesn\'t include music videos from your own images.']);
        }

        $this->ensureRoom($user);

        $file = $this->storage->putCharacter($user, (string) $sheet->getRealPath(), $sheet->extension() ?: 'jpg', true);

        return VideoCharacter::query()
            ->create([
                'user_id' => $user->id,
                'name' => $name,
                'description' => $description ?? '',
                'sheet' => $file,
                'uploaded' => true,
            ]);
    }

    /**
     * The person's saved characters with these ids, checked for casting:
     * all theirs, and their own uploads only on plans that allow them.
     *
     * @param  list<int>  $ids
     * @return array<int, VideoCharacter> By id
     */
    public function forCasting(User $user, array $ids): array
    {
        $characters = VideoCharacter::query()
            ->where('user_id', $user->id)
            ->whereKey($ids)
            ->get()
            ->keyBy('id');

        if ($characters->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['saved' => 'A character isn\'t one of yours.']);
        }

        if ($characters->contains('uploaded', true) && ! $user->feature(Feature::VIDEO_OWN_PHOTOS)) {
            throw ValidationException::withMessages(['saved' => 'Your plan doesn\'t include music videos from your own images.']);
        }

        return $characters->all();
    }

    /**
     * Forget a saved character and its sheet. Videos keep their own copies.
     */
    public function delete(VideoCharacter $character): void
    {
        $this->storage->delete($character->sheet);
        $character->delete();
    }

    protected function ensureRoom(User $user): void
    {
        $count = VideoCharacter::query()
            ->where('user_id', $user->id)
            ->count();

        if ($count >= VideoCharacter::MAX) {
            throw ValidationException::withMessages(['character' => 'You can keep up to ' . VideoCharacter::MAX . ' characters. Remove one first.']);
        }
    }
}
