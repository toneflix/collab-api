<?php

namespace App\Services\Assistant\Tools\Content;

use App\Models\Safety;
use App\Models\User;
use App\Services\Moderation\Reportables;
use Illuminate\Validation\Rule;

class ModerationQueueTool extends ContentTool
{
    public function name(): string
    {
        return 'moderation_queue';
    }

    public function description(): string
    {
        return 'Content with pending reports or automatic flags, most reported first, 10 a page, with the queue\'s totals.';
    }

    public function input(): array
    {
        $sources = [Safety::TYPE_REPORT, Safety::TYPE_MATCH, Safety::TYPE_LYRICS];

        return [
            'type' => [['nullable', Rule::in(app(Reportables::class)->names())], 'Only this kind of content: ' . implode(', ', app(Reportables::class)->names())],
            'reason' => [['nullable', Rule::in(Safety::$allowedReasons)], 'Only this report reason: ' . implode(', ', Safety::$allowedReasons)],
            'source' => [['nullable', Rule::in($sources)], 'report (people), match (copied audio) or lyrics (flagged words)'],
            'search' => ['nullable|string|max:100', 'Text in the content\'s title or name'],
            'page' => $this->pageField(),
        ];
    }

    public function run(User $admin, array $input): array
    {
        $rows = $this
            ->queue()
            ->pending($input, self::PER_PAGE, $this->page($input));

        return [
            'totals' => $this->queue()->stats(),
            ...$this->paged($rows, fn(Safety $row) => [
                'item' => $this->queue()->item($row->model_type, $row->model_id),
                'reports' => (int) $row->reports_count,
                'reasons' => $row->reason_counts,
                'latestReports' => $row->latestReports
                    ->map(fn(Safety $report) => [
                        'source' => $report->type,
                        'reason' => $report->reason,
                        'details' => str((string) $report->details)->limit(200)->toString(),
                        'by' => $report->user?->username,
                    ])
                    ->all(),
                'takenDown' => (bool) $row->activeTakedown,
            ]),
        ];
    }
}
