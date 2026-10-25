<?php

namespace App\Services\Admin;

use App\Http\Resources\SubscriptionResource;
use App\Models\AiCreditEntry;
use App\Models\AiJob;
use App\Models\Project;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use App\Services\Ai\Credits;
use App\Services\Limits;
use App\Services\Payment\Payouts;
use App\Services\Payment\Subscriptions;

/**
 * Numbers about one user for the admin user page.
 */
class UserInsights
{
    public function __construct(protected Payouts $payouts, protected Subscriptions $subscriptions) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        return [
            'currency' => $this->payouts->currency(),
            'content' => $this->content($user),
            'plan' => $this->plan($user),
            'earnings' => $this->payouts->summary($user),
            'spend' => $this->spend($user),
            'ai' => $this->ai($user),
            'storage' => Limits::for($user)->storage(),
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function content(User $user): array
    {
        return [
            'projects' => Project::query()->withoutGlobalScopes()->where('user_id', $user->id)->count(),
            'collabs' => $user->collabProjects()->withoutGlobalScopes()->count(),
            'releases' => Release::query()->withoutGlobalScopes()->where('user_id', $user->id)->count(),
            'tracks' => Track::query()->withoutGlobalScopes()
                ->whereIn('release_id', Release::query()->withoutGlobalScopes()->select('id')->where('user_id', $user->id))
                ->count(),
            'followers' => $user->followers()->count(),
            'following' => $user->followings()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function plan(User $user): array
    {
        $current = $this->subscriptions->current($user);
        $plan = $current?->planModel ?? $user->current_plan;

        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'isDefault' => (bool) $plan->is_default,
            'current' => $current ? new SubscriptionResource($current) : null,
            // Includes periods paid in advance
            'paidUntil' => $user->subscriptions()->toBase()->reorder()->where('ends_at', '>', now())->max('ends_at'),
        ];
    }

    /**
     * What the user has paid for on the platform.
     *
     * @return array{total: float, count: int, byPurpose: array<int, array{purpose: ?string, count: int, amount: float}>}
     */
    protected function spend(User $user): array
    {
        $rows = $user->transactions()->toBase()
            ->where('status', 'complete')
            ->selectRaw('purpose, count(*) as count, coalesce(sum(amount), 0) as amount')
            ->groupBy('purpose')
            ->orderByDesc('amount')
            ->get()
            ->map(fn($row) => ['purpose' => $row->purpose, 'count' => (int) $row->count, 'amount' => (float) $row->amount]);

        return [
            'total' => (float) $rows->sum('amount'),
            'count' => (int) $rows->sum('count'),
            'byPurpose' => $rows->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function ai(User $user): array
    {
        $used = AiCreditEntry::query()->toBase()
            ->where('user_id', $user->id)
            ->whereIn('type', [AiCreditEntry::SPEND, AiCreditEntry::REFUND])
            ->selectRaw('coalesce(-sum(credits), 0) as used')
            ->selectRaw('coalesce(-sum(case when created_at >= ? then credits else 0 end), 0) as month', [now()->startOfMonth()])
            ->first();

        $jobs = AiJob::query()->toBase()
            ->where('user_id', $user->id)
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as failed', [AiJob::FAILED])
            ->first();

        return [
            'balance' => Credits::for($user)->balance(),
            'used' => (int) $used->used,
            'usedThisMonth' => (int) $used->month,
            'jobs' => (int) $jobs->total,
            'failedJobs' => (int) $jobs->failed,
        ];
    }
}
