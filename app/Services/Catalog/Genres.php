<?php

namespace App\Services\Catalog;

use App\Models\Genre;
use App\Services\Moderation\Moderation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Toneflix\LaravelFileable\Media;

/**
 * Adds, edits and removes genres. A genre in use is removed by moving its
 * tracks, releases and fans to another genre first.
 */
class Genres
{
    /** The permission managing genres needs */
    public const PERMISSION = Moderation::PERMISSION;

    /**
     * Genres for the admin list, with how much uses each.
     *
     * @param  array{search?: ?string, cover?: ?string, sort?: ?string}  $filters
     */
    public function query(array $filters = []): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $cover = $filters['cover'] ?? null;

        return Genre::query()
            ->withCount(['tracks', 'releases', 'fans'])
            ->when($search !== '', fn(Builder $q) => $q->where(fn(Builder $q) => $q
                ->whereLike('title', "%{$search}%")
                ->orWhereLike('slug', "%{$search}%")))
            ->when($cover === 'with', fn(Builder $q) => $q->whereNotNull('image'))
            ->when($cover === 'without', fn(Builder $q) => $q->whereNull('image'))
            ->when(
                ($filters['sort'] ?? null) === 'used',
                fn(Builder $q) => $q
                    ->orderByDesc('tracks_count')
                    ->orderBy('title'),
                fn(Builder $q) => $q->orderBy('title'),
            );
    }

    /**
     * Save a genre's details. A new cover in the request's `image` field is
     * stored by the model when it saves.
     *
     * @param  array{title: string, slug?: ?string, description?: ?string, remove_image?: bool}  $data
     */
    public function save(Genre $genre, array $data): Genre
    {
        $genre->title = $data['title'];
        $genre->description = $data['description'] ?? null;
        $genre->prompt = $data['prompt'] ?? null;

        if (! empty($data['slug'])) {
            $genre->slug = $data['slug'];
        }

        if (! empty($data['remove_image']) && $genre->image) {
            $genre->removeFile('image', 'artwork');
            $genre->image = null;
        }

        $genre->save();

        return $genre;
    }

    /**
     * Give a genre a new cover, replacing the one it has.
     */
    public function cover(Genre $genre, UploadedFile $image): Genre
    {
        $media = new Media($genre->disk);

        $genre->image = $media->save('artwork', $image, $genre->image);
        $genre->saveQuietly();

        return $genre;
    }

    /**
     * Remove a genre. Its tracks, releases, studio tracks and fans move to
     * $moveTo; without one, only an unused genre can go.
     */
    public function delete(Genre $genre, ?Genre $moveTo = null): void
    {
        if ($moveTo && $moveTo->is($genre)) {
            throw ValidationException::withMessages(['move_to' => 'Pick a different genre to move its music to.']);
        }

        if (! $moveTo && $this->inUse($genre)) {
            throw ValidationException::withMessages(['move_to' => 'This genre is in use. Pick a genre to move its music to.']);
        }

        DB::transaction(function () use ($genre, $moveTo) {
            if ($moveTo) {
                $this->move($genre, $moveTo);
            }

            if ($genre->image) {
                $genre->removeFile('image', 'artwork');
            }

            $genre->delete();
        });
    }

    public function inUse(Genre $genre): bool
    {
        $uses = [$genre->tracks(), $genre->releases(), $genre->projectTracks()];

        return collect($uses)->contains(fn($relation) => $relation
            ->withoutGlobalScopes()
            ->exists());
    }

    /**
     * Point everything tagged with one genre at another, in one statement
     * per table.
     */
    protected function move(Genre $from, Genre $to): void
    {
        foreach (['tracks', 'releases', 'project_tracks'] as $table) {
            DB::table($table)
                ->where('genre_id', $from->id)
                ->update(['genre_id' => $to->id]);
        }

        $fans = DB::table('genre_user')
            ->where('genre_id', $from->id)
            ->select('user_id', DB::raw((int) $to->id . ' as genre_id'), 'created_at', 'updated_at');

        DB::table('genre_user')->insertOrIgnoreUsing(['user_id', 'genre_id', 'created_at', 'updated_at'], $fans);
    }
}
