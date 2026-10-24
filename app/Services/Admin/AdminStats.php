<?php

namespace App\Services\Admin;

use App\Enums\PayoutStatus;
use App\Helpers\Providers;
use App\Models\Payout;
use App\Models\Project;
use App\Models\Release;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Moderation\ModerationQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Numbers for the admin overview. Each section shows only to admins who manage that area.
 */
class AdminStats
{
    /** Permissions that make someone an admin. */
    public const PERMISSIONS = ['manage-configuration', 'manage-plans', 'manage-payouts', 'manage-content'];

    public function __construct(protected ModerationQueue $moderation, protected int $days = 30) {}

    public static function allows(?User $user): bool
    {
        return $user !== null && collect(self::PERMISSIONS)->contains(fn ($p) => $user->checkPermissionTo($p));
    }

    /**
     * @return array<string, mixed>
     */
    public function for(User $admin): array
    {
        $can = fn (string ...$permissions) => collect($permissions)->contains(fn ($p) => $admin->checkPermissionTo($p));

        return array_filter([
            'days' => $this->days,
            'currency' => Providers::config('app_currency', 'NGN'),
            'people' => $this->people(),
            'content' => $this->content(),
            'revenue' => $can('manage-plans', 'manage-payouts') ? $this->revenue() : null,
            'payouts' => $can('manage-payouts') ? $this->payouts() : null,
            'moderation' => $can('manage-content') ? $this->moderation->stats() : null,
        ], fn ($value) => $value !== null);
    }

    protected function since(): Carbon
    {
        return now()->subDays($this->days - 1)->startOfDay();
    }

    /**
     * Users without the per-user counts the model adds by default.
     *
     * @return Builder<User>
     */
    protected function users()
    {
        return User::query()->withoutGlobalScopes();
    }

    /**
     * @return array{users: int, new: int, subscribers: int, daily: array<int, array{date: string, value: int}>}
     */
    protected function people(): array
    {
        return [
            'users' => $this->users()->count(),
            'new' => $this->users()->where('created_at', '>=', $this->since())->count(),
            'subscribers' => Subscription::query()->active()->distinct()->count('user_id'),
            'daily' => $this->daily(
                $this->users()->toBase()->where('created_at', '>=', $this->since())
                    ->selectRaw('date(created_at) as day, count(*) as value')
                    ->groupBy('day')
                    ->pluck('value', 'day')
            ),
        ];
    }

    /**
     * @return array{releases: int, projects: int, newReleases: int, newProjects: int}
     */
    protected function content(): array
    {
        return [
            'releases' => Release::query()->count(),
            'projects' => Project::query()->count(),
            'newReleases' => Release::query()->where('created_at', '>=', $this->since())->count(),
            'newProjects' => Project::query()->where('created_at', '>=', $this->since())->count(),
        ];
    }

    /**
     * Completed payments. "kept" is what stays with the platform after creators' shares.
     *
     * @return array<string, mixed>
     */
    protected function revenue(): array
    {
        $paid = fn () => Transaction::query()->where('status', 'complete');

        $total = $paid()->toBase()
            ->selectRaw('coalesce(sum(amount), 0) as amount, coalesce(sum(amount - recipient_share), 0) as kept')
            ->first();

        $recent = $paid()->where('paid_at', '>=', $this->since());

        return [
            'total' => (float) $total->amount,
            'kept' => (float) $total->kept,
            'recent' => (float) (clone $recent)->sum('amount'),
            'byPurpose' => (clone $recent)->toBase()
                ->selectRaw('purpose, count(*) as count, coalesce(sum(amount), 0) as amount')
                ->groupBy('purpose')
                ->orderByDesc('amount')
                ->get()
                ->map(fn ($row) => ['purpose' => $row->purpose, 'count' => (int) $row->count, 'amount' => (float) $row->amount])
                ->all(),
            'daily' => $this->daily(
                (clone $recent)->toBase()->selectRaw('date(paid_at) as day, sum(amount) as value')->groupBy('day')->pluck('value', 'day'),
                float: true,
            ),
        ];
    }

    /**
     * @return array{pending: int, pendingAmount: float, processing: int, paid: float}
     */
    protected function payouts(): array
    {
        $row = Payout::query()->toBase()
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as pending', [PayoutStatus::PENDING->value])
            ->selectRaw('coalesce(sum(case when status = ? then amount else 0 end), 0) as pending_amount', [PayoutStatus::PENDING->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as processing', [PayoutStatus::PROCESSING->value])
            ->selectRaw('coalesce(sum(case when status = ? then amount else 0 end), 0) as paid', [PayoutStatus::PAID->value])
            ->first();

        return [
            'pending' => (int) $row->pending,
            'pendingAmount' => (float) $row->pending_amount,
            'processing' => (int) $row->processing,
            'paid' => (float) $row->paid,
        ];
    }

    /**
     * One entry per day in the window, with zero for days that had nothing.
     *
     * @param  Collection<string, mixed>  $values
     * @return array<int, array{date: string, value: int|float}>
     */
    protected function daily(Collection $values, bool $float = false): array
    {
        return collect(range($this->days - 1, 0))->map(function (int $ago) use ($values, $float) {
            $date = now()->subDays($ago)->toDateString();
            $value = $values->get($date, 0);

            return ['date' => $date, 'value' => $float ? (float) $value : (int) $value];
        })->all();
    }
}
