<?php

namespace App\Services\Assistant\Tools\Content;

use App\Models\User;
use App\Services\Moderation\Reportables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class FindContent extends ContentTool
{
    /** Matches of each kind when no kind is given */
    public const PER_TYPE = 3;

    public function name(): string
    {
        return 'find_content';
    }

    public function description(): string
    {
        return 'Search releases (albums), tracks, songs (compositions), projects, sounds, comments and users by title, name, text or id, taken down content included. Give a type to page through one kind, 10 a page; without one you get the top 3 of each kind.';
    }

    public function input(): array
    {
        $types = app(Reportables::class)->names();

        return [
            'search' => ['required|string|max:100', 'Text to look for, or an id'],
            'type' => [['nullable', Rule::in($types)], 'Only this kind: ' . implode(', ', $types)],
            'page' => $this->pageField(),
        ];
    }

    public function run(User $admin, array $input): array
    {
        $reportables = app(Reportables::class);

        if ($input['type'] ?? null) {
            $page = $reportables
                ->search($input['type'], $input['search'])
                ->paginate(self::PER_PAGE, ['*'], 'page', $this->page($input));

            return $this->paged($page, fn(Model $item) => $this->brief($item));
        }

        return collect($reportables->names())
            ->mapWithKeys(fn(string $type) => [$type => $reportables
                ->search($type, $input['search'])
                ->limit(self::PER_TYPE)
                ->get()
                ->map(fn(Model $item) => $this->brief($item))
                ->all()])
            ->all();
    }
}
