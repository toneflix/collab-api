<?php

namespace App\Services\Assistant\Tools\Content;

use App\Models\User;
use Illuminate\Validation\Rule;

class CloseReports extends ContentTool
{
    public function name(): string
    {
        return 'close_reports';
    }

    public function description(): string
    {
        return 'Close the pending reports on content without taking it down: "dismiss" when it breaks no rules (reporters are told), or "resolve" when it was handled another way.';
    }

    public function input(): array
    {
        return [
            ...$this->itemFields(),
            'outcome' => [['required', Rule::in(['dismiss', 'resolve'])], 'dismiss or resolve'],
        ];
    }

    public function confirms(array $input): bool
    {
        return true;
    }

    public function summary(User $admin, array $input): string
    {
        $item = $this->item($input);

        return $input['outcome'] === 'dismiss'
            ? "Dismiss the reports on {$this->label($item)}"
            : "Mark the reports on {$this->label($item)} as handled";
    }

    public function run(User $admin, array $input): array
    {
        $item = $this->item($input);

        $closed = $input['outcome'] === 'dismiss'
            ? $this->moderation()->dismiss($item, $admin)
            : $this->moderation()->resolve($item, $admin);

        return ['closed' => $closed];
    }

    protected function confirmNote(): string
    {
        return ' Needs the admin\'s confirmation.';
    }
}
