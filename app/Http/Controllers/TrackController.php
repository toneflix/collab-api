<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Enums\MusicalKey;
use App\Http\Resources\ActionResource;
use App\Http\Resources\StemDownloadCollection;
use App\Http\Resources\TrackCollection;
use App\Http\Resources\TrackResource;
use App\Models\ProjectTrackStem;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use App\Services\Releases\Downloads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class TrackController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, ?Release $release = null)
    {
        $query = $release
            ? $release->tracks()->getQuery()
            : Track::query()->whereHas('release', fn(Builder $q) => $q->live());

        $filters = str($request->filters)
            ->remove(' ')
            ->explode(',');

        $query
            ->when($request->filled('search'), fn(Builder $q) => $q->search((string) $request->search)->with('lyric'))
            ->when($request->has('genre'), fn(Builder $q) => $q->genre($request->genre))
            ->when(
                $request->filled('bpm_min') || $request->filled('bpm_max'),
                fn(Builder $q) => $q->bpmBetween(
                    $request->filled('bpm_min') ? (float) $request->bpm_min : null,
                    $request->filled('bpm_max') ? (float) $request->bpm_max : null,
                )
            )
            ->when($request->filled('key'), fn(Builder $q) => $q->inKey(
                str($request->key)->explode(',')->map(fn($key) => MusicalKey::parse($key))->filter()->values()->all(),
                $request->boolean('compatible'),
            ))
            ->when($filters->contains('latest'), fn(Builder $q) => $q->latestInPeriod($request->period))
            ->when(
                $filters->contains('liked') && $request->user('sanctum'),
                fn(Builder $q) => $q->likedBy($request->user('sanctum'))
            )
            ->when(
                $filters->contains('own_collabs') && $request->user('sanctum'),
                fn(Builder $q) => $q->userCollabs($request->user('sanctum'), $request->period)
            )
            ->when(
                $filters->contains('recently_played') && $request->user('sanctum'),
                fn(Builder $q) => $q->recentlyPlayed($request->user('sanctum'), 3)
            )->when(
                $filters->contains('trending'),
                fn(Builder $q) => $q->popular('top', $request->period)
            )->when(
                $request->has('artist_id') && ! $filters->contains('features'),
                fn(Builder $q) => $q->forArtist($request->artist_id)
            )->when(
                $filters->contains('features') && $request->has('artist_id'),
                fn(Builder $q) => $q->forFeaturedArtist($request->artist_id)
            );

        $tracks = $query->paginate($request->perPage(15));

        return (new TrackCollection($tracks))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Release $release, Track $track)
    {
        $track->stream(0, $request->user('sanctum'));

        return (new TrackResource($track))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Release $release, Track $track)
    {
        $this->validate($request, [
            'duration' => 'nullable|numeric|min:0',
        ]);

        $track->stream($request->integer('duration'), $request->user('sanctum'));

        return (new TrackResource($track))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Download links for a track's stems, for its owner and people who bought them.
     */
    public function stems(Request $request, Track $track)
    {
        abort_unless($track->stemsUnlockedFor($request->user()), HttpStatus::PAYMENT_REQUIRED->value, 'Buy the stems to download them.');

        $query = $track->projectTrack?->stems() ?? ProjectTrackStem::query()->whereKey([]);

        $stems = $query
            ->whereNotNull('src')
            ->oldest('id')
            ->paginate($request->perPage(50));

        return (new StemDownloadCollection($stems))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * A download link for offline listening, following the rules in Downloads.
     */
    public function download(Request $request, Track $track)
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($track->isUnlockedFor($user), HttpStatus::PAYMENT_REQUIRED->value, 'Buy this release to download it.');

        abort_unless(
            Downloads::available($track, $user),
            HttpStatus::FORBIDDEN->value,
            'This track is not available for download.'
        );

        abort_unless(
            Downloads::allowedFor($track, $user),
            HttpStatus::FORBIDDEN->value,
            'Downloads are not included in your plan.'
        );

        $url = $track->getFiles['audio']['dynamicLink'] ?? '';

        if ($url === '' && $track->projectTrack) {
            $url = $track->projectTrack->getFiles['preview']['dynamicLink'] ?? '';
        }

        abort_if($url === '', HttpStatus::NOT_FOUND->value, 'This track has no audio yet.');

        $filename = str($track->title)
            ->slug()
            ->append('.', $track->meta['format'] ?? 'mp3')
            ->toString();

        $download = [
            'url' => $url,
            'filename' => $filename,
        ];

        return (new ActionResource($download))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Track $track)
    {
        //
    }
}
