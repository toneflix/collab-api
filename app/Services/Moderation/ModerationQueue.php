<?php

namespace App\Services\Moderation;

use App\Contracts\CanBeTakenDown;
use App\Models\Release;
use App\Models\Safety;
use App\Models\Scopes\RestrictBlockedUserScope;
use App\Models\Takedown;
use App\Models\User;
use App\Services\Artists\ArtistClaims;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What moderators and owners see: content waiting for review, grouped so
 * each piece shows once with all its reports, and takedowns.
 */
class ModerationQueue
{
    /** Latest reports shown with each item */
    public const REPORTS_PER_ITEM = 5;

    public function __construct(
        protected Reportables $reportables,
        protected ArtistClaims $claims,
    ) {}

    /**
     * Content with pending reports, most reported first. Each row carries its
     * reason counts, latest reports and takedown in force.
     *
     * @param  array{type?: ?string, reason?: ?string, source?: ?string, search?: ?string}  $filters
     */
    public function pending(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $page = $this->filtered(Safety::query()->pending(), $filters)
            ->select('model_type', 'model_id')
            ->selectRaw('COUNT(*) as reports_count')
            ->selectRaw('SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) as matches_count', [Safety::TYPE_MATCH])
            ->selectRaw('MAX(created_at) as last_reported_at')
            ->groupBy('model_type', 'model_id')
            ->orderByDesc('reports_count')
            ->orderByDesc('last_reported_at')
            ->paginate($perPage);

        $reasons = $this->reasonCounts($page->getCollection());

        $page->getCollection()->each(function (Safety $row) use ($reasons) {
            $row->setAttribute('reason_counts', $reasons[$row->model_type.':'.$row->model_id] ?? []);

            $row->setRelation('latestReports', Safety::query()
                ->pending()
                ->where('model_type', $row->model_type)
                ->where('model_id', $row->model_id)
                ->with('user')
                ->latest('id')
                ->limit(self::REPORTS_PER_ITEM)
                ->get());

            $row->setRelation('activeTakedown', Takedown::query()
                ->where('model_type', $row->model_type)
                ->where('model_id', $row->model_id)
                ->inForce()
                ->latest('id')
                ->first());
        });

        return $page;
    }

    /**
     * How many pieces of content wait for review, and how many appeals.
     *
     * @return array{pending: int, appeals: int, releases: int, claims: int}
     */
    public function stats(): array
    {
        return [
            'pending' => DB::query()->fromSub(
                Safety::query()->pending()->select('model_type', 'model_id')->groupBy('model_type', 'model_id'),
                'items'
            )->count(),
            'appeals' => Takedown::query()->where('status', Takedown::APPEALED)->count(),
            'releases' => Release::query()->withoutGlobalScopes()->inReview()->count(),
            'claims' => $this->claims->pendingCount(),
        ];
    }

    /**
     * Published releases waiting for an admin to approve them, oldest first.
     */
    public function releases(?string $search = null, int $perPage = 20): LengthAwarePaginator
    {
        return Release::query()
            ->withoutGlobalScope(RestrictBlockedUserScope::class)
            ->inReview()
            ->when($search, fn (Builder $q, string $search) => $q->where(fn (Builder $q) => $q
                ->whereLike('title', "%{$search}%")
                ->orWhereLike('version', "%{$search}%")
                ->orWhereHas('user', fn (Builder $q) => $q->withoutGlobalScopes()->where(fn (Builder $q) => $q
                    ->whereLike('username', "%{$search}%")
                    ->orWhereLike('firstname', "%{$search}%")
                    ->orWhereLike('lastname', "%{$search}%")))))
            ->with(['user', 'artist', 'project', 'latestAiReview'])
            ->oldest('updated_at')
            ->paginate($perPage);
    }

    public function takedowns(?string $status = null, ?string $search = null, int $perPage = 20): LengthAwarePaginator
    {
        return Takedown::query()
            ->when($status, fn (Builder $q) => $q->where('status', $status))
            ->when($search, fn (Builder $q, string $search) => $this->reportables->whereContentMatches($q, $search))
            ->with('moderator')
            ->when($status === Takedown::APPEALED, fn (Builder $q) => $q->oldest('appealed_at'), fn (Builder $q) => $q->latest('id'))
            ->paginate($perPage);
    }

    /**
     * Takedowns of content the user owns.
     */
    public function takedownsFor(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return Takedown::query()
            ->where(function (Builder $query) use ($user) {
                foreach (Reportables::TYPES as $class) {
                    $model = new $class;
                    if (! $model instanceof CanBeTakenDown) {
                        continue;
                    }

                    $owned = $class::query()->withoutGlobalScopes()->select($model->qualifyColumn('id'));
                    $model->whereOwnedBy($owned, (int) $user->id);

                    $query->orWhere(fn (Builder $q) => $q
                        ->where('model_type', $model->getMorphClass())
                        ->whereIn('model_id', $owned));
                }
            })
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * A short description of some content for moderation screens, or null
     * when it was deleted.
     *
     * @return array<string, mixed>|null
     */
    public function item(string $class, int|string $id): ?array
    {
        $model = $this->reportables->find($class, $id);
        if (! $model) {
            return null;
        }

        $ownerId = $model->moderationOwnerId();
        $owner = $ownerId ? User::query()->withoutGlobalScopes()->find($ownerId) : null;

        return [
            'type' => $this->reportables->nameFor($model::class),
            'id' => $model->getKey(),
            ...$model->moderationSummary(),
            'canTakeDown' => $model instanceof CanBeTakenDown,
            'takenDown' => $model instanceof CanBeTakenDown && $model->isTakenDown(),
            'owner' => $owner ? [
                'id' => $owner->id,
                'name' => $owner->fullname,
                'username' => $owner->username,
            ] : null,
        ];
    }

    /**
     * @param  array{type?: ?string, reason?: ?string, source?: ?string, search?: ?string}  $filters
     */
    protected function filtered(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('model_type', $this->reportables->classFor($type)))
            ->when($filters['reason'] ?? null, fn (Builder $q, string $reason) => $q->where('reason', $reason))
            ->when($filters['source'] ?? null, fn (Builder $q, string $source) => $q->where('type', $source))
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $this->reportables->whereContentMatches($q, $search));
    }

    /**
     * Pending report counts by reason for each item on a page.
     *
     * @return array<string, array<string, int>>
     */
    protected function reasonCounts($rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $counts = Safety::query()
            ->pending()
            ->where(function (Builder $query) use ($rows) {
                foreach ($rows as $row) {
                    $query->orWhere(fn (Builder $q) => $q
                        ->where('model_type', $row->model_type)
                        ->where('model_id', $row->model_id));
                }
            })
            ->select('model_type', 'model_id', 'reason')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('model_type', 'model_id', 'reason')
            ->get();

        $grouped = [];
        foreach ($counts as $count) {
            $grouped[$count->model_type.':'.$count->model_id][$count->reason] = (int) $count->total;
        }

        return $grouped;
    }
}
