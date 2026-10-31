<?php

namespace App\Http\Controllers\Account;

use App\Enums\Feature;
use App\Enums\HttpStatus;
use App\Enums\TrackOrigin;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActionResource;
use App\Http\Resources\StreamTrackCollection;
use App\Models\Project;
use App\Models\Release;
use App\Models\Track;
use App\Models\TrackPlay;
use App\Services\Streams\StreamEarnings;
use App\Services\Streams\StreamRules;
use App\Services\Streams\StreamStats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class StreamController extends Controller
{
    /**
     * The creator's paid streams this month, an estimate, what streams earned so far, and the last twelve months.
     */
    public function show(StreamEarnings $earnings, StreamStats $stats)
    {
        $ownerId = $this->user->id;

        $summary = [
            ...$earnings->summary($this->user),
            'eligible' => (bool) $this->user->feature(Feature::EARN_STREAMS),
            'rules' => StreamRules::current()->toArray(),
            'months' => $stats->months(fn(QueryBuilder $q) => $q->where('track_plays.owner_id', $ownerId)),
        ];

        return (new ActionResource($summary))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Each of the creator's tracks with its plays, paid streams and earnings in a month.
     */
    public function tracks(Request $request, StreamStats $stats)
    {
        $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'release_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
        ]);

        $ownerId = $this->user->id;
        $month = $request->filled('month')
            ? Carbon::createFromFormat('!Y-m', $request->string('month')->toString())->startOfMonth()
            : now()->startOfMonth();
        $releaseId = $request->integer('release_id') ?: null;
        $projectId = $request->integer('project_id') ?: null;
        $rate = $stats->rate($month);

        $owned = Release::query()
            ->withoutGlobalScopes()
            ->where('user_id', $ownerId)
            ->when($releaseId, fn(Builder $q) => $q->whereKey($releaseId))
            ->when($projectId, fn(Builder $q) => $q->where('project_id', $projectId))
            ->select('id');
        $plays = TrackPlay::query()
            ->toBase()
            ->select('track_id')
            ->selectRaw('count(*) as plays')
            ->selectRaw('sum(case when paid and not held then 1 else 0 end) as paid')
            ->selectRaw('sum(case when paid and held then 1 else 0 end) as held')
            ->where('owner_id', $ownerId)
            ->whereBetween('played_on', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->groupBy('track_id');

        $tracks = Track::query()
            ->withoutGlobalScopes()
            ->with('release')
            ->leftJoinSub($plays, 'stats', 'stats.track_id', '=', 'tracks.id')
            ->whereIn('tracks.release_id', $owned)
            ->select('tracks.*')
            ->selectRaw('coalesce(stats.plays, 0) as stat_plays')
            ->selectRaw('coalesce(stats.paid, 0) as stat_paid')
            ->selectRaw('coalesce(stats.held, 0) as stat_held')
            ->orderByDesc('stat_paid')
            ->orderByDesc('stat_plays')
            ->orderBy('tracks.id')
            ->paginate($request->perPage(20));

        $tracks
            ->getCollection()
            ->each(function (Track $track) use ($rate) {
                $origin = $track->origin ?? TrackOrigin::AGENTIC;
                $weight = (float) ($rate['weights'][$origin->value] ?? 0);

                $track->setAttribute('stat_earned', round((int) $track->stat_paid * $weight * $rate['rate'], 2));
            });

        return (new StreamTrackCollection($tracks))
            ->additional([
                'month' => [
                    'month' => $month->format('Y-m'),
                    ...$rate,
                ],
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Stream stats for one of the creator's tracks, albums or projects.
     */
    public function stats(string $type, int $id, StreamStats $stats)
    {
        $ownerId = $this->user->id;

        [$item, $trackIds] = match ($type) {
            'track' => $this->track($id, $ownerId),
            'album' => $this->album($id, $ownerId),
            'project' => $this->project($id, $ownerId),
        };

        $report = [
            'item' => $item,
            ...$stats->report(fn(QueryBuilder $q) => $q->whereIn('track_plays.track_id', $trackIds)),
            'rules' => StreamRules::current()->toArray(),
        ];

        return (new ActionResource($report))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * @return array{0: array<string, mixed>, 1: Builder}
     */
    protected function track(int $id, int $ownerId): array
    {
        $track = Track::query()
            ->withoutGlobalScopes()
            ->with('release')
            ->findOrFail($id);

        abort_unless($track->moderationOwnerId() === $ownerId, HttpStatus::FORBIDDEN->value, 'Only the track\'s owner sees its stats.');

        $item = [
            'type' => 'track',
            'id' => $track->id,
            'slug' => $track->slug,
            'title' => $track->title,
            'imageUrl' => $track->files['image'] ?? null,
            'artist' => $track->artist()->withoutGlobalScopes()->value('username'),
            'origin' => $track->origin?->value,
            'releaseId' => $track->release_id,
        ];
        $trackIds = Track::query()
            ->withoutGlobalScopes()
            ->whereKey($track->id)
            ->select('id');

        return [$item, $trackIds];
    }

    /**
     * @return array{0: array<string, mixed>, 1: Builder}
     */
    protected function album(int $id, int $ownerId): array
    {
        $release = Release::query()
            ->withoutGlobalScopes()
            ->findOrFail($id);

        abort_unless((int) $release->user_id === $ownerId, HttpStatus::FORBIDDEN->value, 'Only the album\'s owner sees its stats.');

        $item = [
            'type' => 'album',
            'id' => $release->id,
            'slug' => $release->slug,
            'title' => $release->title,
            'imageUrl' => $release->files['artwork'] ?? null,
            'artist' => $release->artist()->withoutGlobalScopes()->value('username'),
            'releaseId' => $release->id,
        ];
        $trackIds = Track::query()
            ->withoutGlobalScopes()
            ->where('release_id', $release->id)
            ->select('id');

        return [$item, $trackIds];
    }

    /**
     * @return array{0: array<string, mixed>, 1: Builder}
     */
    protected function project(int $id, int $ownerId): array
    {
        $project = Project::query()
            ->withoutGlobalScopes()
            ->findOrFail($id);

        abort_unless((int) $project->user_id === $ownerId, HttpStatus::FORBIDDEN->value, 'Only the project\'s owner sees its stats.');

        $item = [
            'type' => 'project',
            'id' => $project->id,
            'slug' => $project->slug,
            'title' => $project->title,
            'imageUrl' => $project->images['image'] ?? null,
            'projectId' => $project->id,
        ];
        $releases = Release::query()
            ->withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->select('id');
        $trackIds = Track::query()
            ->withoutGlobalScopes()
            ->whereIn('release_id', $releases)
            ->select('id');

        return [$item, $trackIds];
    }
}
