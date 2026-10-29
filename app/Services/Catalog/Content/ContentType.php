<?php

namespace App\Services\Catalog\Content;

use App\Contracts\CanBeTakenDown;
use App\Contracts\Reportable;
use App\Models\User;
use App\Services\Moderation\Reportables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * One kind of content admins list, add and edit: how it is found, checked
 * and saved. Deleting goes through moderation.
 */
abstract class ContentType
{
    public const SORTS = ['latest', 'oldest', 'title'];

    public function __construct(protected Reportables $reportables) {}

    /**
     * The content's name in the API, the same one moderation uses.
     */
    abstract public function name(): string;

    /**
     * @return class-string<Model&Reportable>
     */
    abstract public function model(): string;

    /**
     * The status filters the admin list offers.
     *
     * @return list<string>
     */
    abstract public function statuses(): array;

    /**
     * Validation rules for adding ($item null) or editing content.
     *
     * @return array<string, mixed>
     */
    abstract public function rules(?Model $item = null): array;

    /**
     * Create the content from validated values.
     *
     * @param  array<string, mixed>  $data
     */
    abstract public function create(array $data, User $admin): Model;

    /**
     * Save validated values onto existing content.
     *
     * @param  array<string, mixed>  $data
     */
    abstract public function update(Model $item, array $data, User $admin): Model;

    /**
     * The values the admin form edits.
     *
     * @return array<string, mixed>
     */
    abstract public function fields(Model $item): array;

    abstract protected function whereStatus(Builder $query, string $status): void;

    /**
     * Relations the admin list loads with each row.
     *
     * @return array<int|string, mixed>
     */
    protected function with(): array
    {
        return [];
    }

    protected function titleColumn(): string
    {
        return 'title';
    }

    /**
     * Content for the admin list, hidden and taken down content included.
     *
     * @param  array{search?: ?string, status?: ?string, user_id?: ?int, sort?: ?string}  $filters
     */
    public function query(array $filters = []): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $sort = $filters['sort'] ?? 'latest';

        return $this
            ->baseQuery()
            ->with($this->with())
            ->when($search !== '', fn(Builder $q) => $this->reportables->whereMatches($q, $this->name(), $search))
            ->when($filters['status'] ?? null, fn(Builder $q, string $status) => $this->whereStatus($q, $status))
            ->when($filters['user_id'] ?? null, fn(Builder $q, int $userId) => $this->whereOwner($q, $userId))
            ->when(
                $sort === 'title',
                fn(Builder $q) => $q->orderBy($q->qualifyColumn($this->titleColumn())),
                fn(Builder $q) => $sort === 'oldest' ? $q->oldest($q->qualifyColumn('id')) : $q->latest($q->qualifyColumn('id')),
            );
    }

    /**
     * One piece of content by id, wherever it is shown.
     */
    public function find(int|string $id): ?Model
    {
        return $this
            ->baseQuery()
            ->find($id);
    }

    protected function baseQuery(): Builder
    {
        $class = $this->model();

        return $class::query()->withoutGlobalScopesExcept([SoftDeletingScope::class]);
    }

    protected function whereOwner(Builder $query, int $userId): void
    {
        $model = $query->getModel();

        if ($model instanceof CanBeTakenDown) {
            $model->whereOwnedBy($query, $userId);

            return;
        }

        $query->where($model->qualifyColumn('user_id'), $userId);
    }
}
