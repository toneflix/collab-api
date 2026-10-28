<?php

namespace App\Services\Assistant\Tools\Content;

use App\Models\Takedown;
use App\Services\Assistant\Tools\AssistantTool;
use App\Services\Moderation\Moderation;
use App\Services\Moderation\ModerationQueue;
use App\Services\Moderation\Reportables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A tool that looks at or moderates content through Moderation and its queue.
 */
abstract class ContentTool extends AssistantTool
{
    public const PER_PAGE = 10;

    public function permissions(): array
    {
        return [Moderation::PERMISSION];
    }

    protected function moderation(): Moderation
    {
        return app(Moderation::class);
    }

    protected function queue(): ModerationQueue
    {
        return app(ModerationQueue::class);
    }

    /**
     * The content the input points at, whether it's taken down or hidden.
     *
     * @param  array<string, mixed>  $input
     */
    protected function item(array $input): Model
    {
        $item = app(Reportables::class)->find($input['type'], $input['id']);

        if (! $item) {
            throw ValidationException::withMessages(['id' => "There is no {$input['type']} #{$input['id']}."]);
        }

        return $item;
    }

    /**
     * A short description of some content, with its owner.
     *
     * @return array<string, mixed>|null
     */
    protected function brief(Model $item): ?array
    {
        return $this->queue()->item($item::class, $item->getKey());
    }

    /**
     * A takedown and what came of it.
     *
     * @return array<string, mixed>
     */
    protected function takedown(Takedown $takedown): array
    {
        return [
            'id' => $takedown->id,
            'item' => $this->queue()->item($takedown->model_type, $takedown->model_id),
            'status' => $takedown->status,
            'reason' => $takedown->reason,
            'note' => $takedown->note,
            'appeal' => $takedown->appeal,
            'resolution' => $takedown->resolution,
            'at' => $takedown->created_at?->toIso8601String(),
        ];
    }

    /**
     * How the summary of a change names the content, like "track #4 “Night Drive”".
     */
    protected function label(Model $item): string
    {
        $type = app(Reportables::class)->nameFor($item::class);
        $title = $item->moderationSummary()['title'] ?? '';

        return trim("{$type} #{$item->getKey()} “{$title}”");
    }

    /**
     * @return array<string, array{0: array<int, mixed>|string, 1: string}>
     */
    protected function itemFields(): array
    {
        $types = app(Reportables::class)->names();

        return [
            'type' => [['required', Rule::in($types)], 'One of ' . implode(', ', $types)],
            'id' => ['required|integer|min:1', 'The content\'s id'],
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function pageField(): array
    {
        return ['nullable|integer|min:1', 'Page of results, from 1'];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function page(array $input): int
    {
        return max(1, (int) ($input['page'] ?? 1));
    }
}
