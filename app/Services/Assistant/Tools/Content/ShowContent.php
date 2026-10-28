<?php

namespace App\Services\Assistant\Tools\Content;

use App\Models\Safety;
use App\Models\Takedown;
use App\Models\User;

class ShowContent extends ContentTool
{
    /** Latest reports and takedowns shown with the content */
    public const HISTORY = 10;

    public function name(): string
    {
        return 'show_content';
    }

    public function description(): string
    {
        return 'One piece of content with its owner, latest pending reports and takedown history.';
    }

    public function input(): array
    {
        return $this->itemFields();
    }

    public function run(User $admin, array $input): array
    {
        $item = $this->item($input);

        $reports = Safety::query()
            ->forItem($item)
            ->pending()
            ->with('user')
            ->latest('id')
            ->limit(self::HISTORY)
            ->get()
            ->map(fn(Safety $report) => [
                'source' => $report->type,
                'reason' => $report->reason,
                'details' => str((string) $report->details)->limit(300)->toString(),
                'by' => $report->user?->username,
                'at' => $report->created_at?->toIso8601String(),
            ])
            ->all();

        $takedowns = Takedown::query()
            ->where('model_type', $item->getMorphClass())
            ->where('model_id', $item->getKey())
            ->latest('id')
            ->limit(self::HISTORY)
            ->get()
            ->map(fn(Takedown $takedown) => $this->takedown($takedown))
            ->all();

        return [
            ...$this->brief($item),
            'pendingReports' => $reports,
            'takedowns' => $takedowns,
        ];
    }
}
