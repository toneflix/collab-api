<?php

namespace App\Services\Assistant\Tools\Content;

use App\Models\Takedown;
use App\Models\User;
use Illuminate\Validation\Rule;

class Takedowns extends ContentTool
{
    public function name(): string
    {
        return 'list_takedowns';
    }

    public function description(): string
    {
        return 'Takedowns, newest first or oldest appeal first, 10 a page.';
    }

    public function input(): array
    {
        return [
            'status' => [['nullable', Rule::in([Takedown::ACTIVE, Takedown::APPEALED, Takedown::RESTORED])], 'active, appealed or restored'],
            'search' => ['nullable|string|max:100', 'Text in the content\'s title or name'],
            'page' => $this->pageField(),
        ];
    }

    public function run(User $admin, array $input): array
    {
        $takedowns = $this
            ->queue()
            ->takedowns($input['status'] ?? null, $input['search'] ?? null, self::PER_PAGE, $this->page($input));

        return $this->paged($takedowns, fn(Takedown $takedown) => $this->takedown($takedown));
    }
}
