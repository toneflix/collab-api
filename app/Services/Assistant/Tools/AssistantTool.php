<?php

namespace App\Services\Assistant\Tools;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;

/**
 * Something the admin assistant can look up or do. A tool works as the admin
 * who asked, through the same services and permission checks as the admin
 * pages, so it can never do more than that admin.
 *
 * Tools that make a destructive change only run once the admin confirms them:
 * the assistant gets a summary to show, and the change waits as an
 * AssistantAction.
 */
abstract class AssistantTool
{
    abstract public function name(): string;

    abstract public function description(): string;

    /**
     * The tool's input: field => [validation rules, what it means].
     *
     * @return array<string, array{0: string|array<int, mixed>, 1: string}>
     */
    abstract public function input(): array;

    /**
     * Do the work and return what the assistant learns from it.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    abstract public function run(User $admin, array $input): array;

    /**
     * Permissions the admin needs to be offered the tool at all.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return [];
    }

    public function allows(User $admin): bool
    {
        return collect($this->permissions())
            ->every(fn(string $permission) => $admin->checkPermissionTo($permission));
    }

    /**
     * Whether this input makes a change the admin must confirm first.
     *
     * @param  array<string, mixed>  $input
     */
    public function confirms(array $input): bool
    {
        return false;
    }

    /**
     * Check the input and the admin's right to act, and describe the change
     * for the admin to confirm.
     *
     * @param  array<string, mixed>  $input
     */
    public function summary(User $admin, array $input): string
    {
        return $this->name();
    }

    /**
     * The input with only known fields, checked against the tool's rules.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input): array
    {
        $rules = collect($this->input())
            ->map(fn(array $field) => $field[0])
            ->all();

        return Validator::make($input, $rules)
            ->validate();
    }

    /**
     * How the tool is described to the model.
     */
    public function describe(): string
    {
        $fields = collect($this->input())
            ->reject(fn(array $field, string $name) => str_contains($name, '.'))
            ->map(function (array $field, string $name) {
                $rules = is_array($field[0]) ? $field[0] : explode('|', $field[0]);
                $required = in_array('required', $rules, true) ? 'required' : 'optional';

                return "    - {$name} ({$required}): {$field[1]}";
            })
            ->join("\n");

        $confirm = $this->confirmNote();

        return trim("- {$this->name()}: {$this->description()}{$confirm}\n{$fields}");
    }

    /**
     * A page of results the model can read, and how to get the next one.
     *
     * @return array{items: list<mixed>, page: int, lastPage: int, total: int}
     */
    protected function paged(LengthAwarePaginator $page, callable $map): array
    {
        return [
            'items' => collect($page->items())
                ->map($map)
                ->values()
                ->all(),
            'page' => $page->currentPage(),
            'lastPage' => $page->lastPage(),
            'total' => $page->total(),
        ];
    }

    protected function confirmNote(): string
    {
        return '';
    }
}
