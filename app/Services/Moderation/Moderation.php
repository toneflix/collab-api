<?php

namespace App\Services\Moderation;

use App\Contracts\CanBeTakenDown;
use App\Contracts\Reportable;
use App\Enums\HttpStatus;
use App\Models\Release;
use App\Models\Safety;
use App\Models\Takedown;
use App\Models\Track;
use App\Models\User;
use App\Notifications\ModerationNotice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Reports, takedowns and appeals. Everything that changes what moderators
 * decided goes through here.
 */
class Moderation
{
    public function __construct(protected Reportables $reportables) {}

    /**
     * File or update someone's report about some content.
     *
     * @param  array<string, mixed>  $claim
     */
    public function report(User $reporter, Model&Reportable $item, string $reason, ?string $details = null, array $claim = []): Safety
    {
        if ($item->moderationOwnerId() === (int) $reporter->id) {
            throw ValidationException::withMessages(['id' => 'You can\'t report your own content.']);
        }

        $report = Safety::query()->forItem($item)->where('user_id', $reporter->id)->first();

        if ($report && $report->status !== Safety::PENDING) {
            throw ValidationException::withMessages(['id' => 'You already reported this, and a moderator reviewed it.']);
        }

        $report ??= new Safety([
            'user_id' => $reporter->id,
            'model_type' => $item->getMorphClass(),
            'model_id' => $item->getKey(),
        ]);

        $report->fill([
            'type' => Safety::TYPE_REPORT,
            'reason' => $reason,
            'details' => $details,
            'data' => $claim ?: null,
            'status' => Safety::PENDING,
        ])->save();

        return $report;
    }

    /**
     * Take content down. Its pending reports are closed, and the owner and
     * reporters are told.
     */
    public function takeDown(Model&CanBeTakenDown $item, User $moderator, string $reason, ?string $note = null): Takedown
    {
        if ($item->takedowns()->inForce()->exists()) {
            throw ValidationException::withMessages(['item' => 'This is already taken down.']);
        }

        $takedown = DB::transaction(function () use ($item, $moderator, $reason, $note) {
            $takedown = $item->takedowns()->create([
                'moderator_id' => $moderator->id,
                'reason' => $reason,
                'note' => $note,
            ]);

            $this->stamp($item, now());
            $this->closeReports($item, $moderator, Safety::ACTIONED, $takedown);

            return $takedown;
        });

        $summary = $item->moderationSummary();
        $label = $this->label($item);

        $this->notifyOwner($item, "Your {$label} was taken down", trim(
            "A moderator took down “{$summary['title']}” for {$this->reasonLabel($reason)}. ".
            ($note ? $note.' ' : '').
            'If you think this is a mistake, you can appeal from Studio > Takedowns.'
        ), $takedown);

        $this->notifyReporters($takedown->reports()->getQuery(), 'Thanks for your report', "We reviewed your report about “{$summary['title']}” and took it down.");

        return $takedown;
    }

    /**
     * Close the pending reports on content without taking it down.
     */
    public function dismiss(Model&Reportable $item, User $moderator): int
    {
        $summary = $item->moderationSummary();
        $pending = Safety::query()->forItem($item)->pending()->pluck('id');

        $closed = $this->closeReports($item, $moderator, Safety::DISMISSED);

        $this->notifyReporters(
            Safety::query()->whereIn('id', $pending),
            'We reviewed your report',
            "We looked at “{$summary['title']}” and didn't find it breaks our rules. Thanks for looking out."
        );

        return $closed;
    }

    /**
     * Close the pending reports on content a moderator handled another way,
     * such as an account they warned.
     */
    public function resolve(Model&Reportable $item, User $moderator): int
    {
        return $this->closeReports($item, $moderator, Safety::ACTIONED);
    }

    /**
     * The owner asks moderators to look at a takedown again. Each takedown can
     * be appealed once.
     */
    public function appeal(Takedown $takedown, User $owner, string $message): Takedown
    {
        $item = $takedown->item();

        abort_unless($item && $item->moderationOwnerId() === (int) $owner->id, HttpStatus::FORBIDDEN->value, 'Only the owner can appeal this.');

        if ($takedown->status !== Takedown::ACTIVE || $takedown->appealed_at) {
            throw ValidationException::withMessages(['message' => 'This takedown can\'t be appealed again.']);
        }

        $takedown->update([
            'status' => Takedown::APPEALED,
            'appeal' => $message,
            'appealed_at' => now(),
        ]);

        return $takedown;
    }

    /**
     * Put taken down content back.
     */
    public function restore(Takedown $takedown, User $moderator, ?string $resolution = null): Takedown
    {
        if (! $takedown->inForce()) {
            throw ValidationException::withMessages(['takedown' => 'This takedown is no longer in force.']);
        }

        $item = $takedown->item();

        DB::transaction(function () use ($takedown, $moderator, $resolution, $item) {
            $takedown->update([
                'status' => Takedown::RESTORED,
                'resolved_by' => $moderator->id,
                'resolution' => $resolution,
                'resolved_at' => now(),
            ]);

            if ($item instanceof CanBeTakenDown) {
                $this->stamp($item, null);
            }
        });

        if ($item instanceof CanBeTakenDown) {
            $summary = $item->moderationSummary();
            $this->notifyOwner($item, "Your {$this->label($item)} is back", trim(
                "We reviewed “{$summary['title']}” again and restored it. ".($resolution ?? '')
            ), $takedown);
        }

        return $takedown;
    }

    /**
     * Keep content down after looking at the owner's appeal.
     */
    public function uphold(Takedown $takedown, User $moderator, ?string $resolution = null): Takedown
    {
        if ($takedown->status !== Takedown::APPEALED) {
            throw ValidationException::withMessages(['takedown' => 'Only appealed takedowns can be upheld.']);
        }

        $takedown->update([
            'status' => Takedown::ACTIVE,
            'resolved_by' => $moderator->id,
            'resolution' => $resolution,
            'resolved_at' => now(),
        ]);

        $item = $takedown->item();
        if ($item instanceof CanBeTakenDown) {
            $summary = $item->moderationSummary();
            $this->notifyOwner($item, 'Your appeal was declined', trim(
                "We reviewed your appeal for “{$summary['title']}”, and it stays down. ".($resolution ?? '')
            ), $takedown);
        }

        return $takedown;
    }

    /**
     * Mark content, and a release's tracks, as taken down or put it back. A
     * track keeps its own takedown when its release comes back.
     */
    protected function stamp(Model&CanBeTakenDown $item, ?\DateTimeInterface $at): void
    {
        $item->newQueryWithoutScopes()->whereKey($item->getKey())->update(['taken_down_at' => $at]);
        $item->taken_down_at = $at;

        if (! $item instanceof Release) {
            return;
        }

        Track::query()
            ->withoutGlobalScopes()
            ->where('release_id', $item->id)
            ->when($at, fn (Builder $q) => $q->whereNull('taken_down_at'))
            ->when(! $at, fn (Builder $q) => $q->whereNotIn('id', Takedown::query()
                ->select('model_id')
                ->where('model_type', (new Track)->getMorphClass())
                ->inForce()))
            ->update(['taken_down_at' => $at]);
    }

    protected function closeReports(Model&Reportable $item, User $moderator, string $status, ?Takedown $takedown = null): int
    {
        return Safety::query()->forItem($item)->pending()->update([
            'status' => $status,
            'reviewed_by' => $moderator->id,
            'reviewed_at' => now(),
            'takedown_id' => $takedown?->id,
            'updated_at' => now(),
        ]);
    }

    protected function notifyOwner(Model&Reportable $item, string $title, string $message, Takedown $takedown): void
    {
        $owner = $item->moderationOwnerId();
        $user = $owner ? User::query()->withoutGlobalScopes()->find($owner) : null;

        $user?->notify(new ModerationNotice($title, $message, [
            'takedown_id' => $takedown->id,
            'status' => $takedown->status,
        ]));
    }

    /**
     * Tell the people behind some reports, a hundred at a time.
     */
    protected function notifyReporters(Builder $reports, string $title, string $message): void
    {
        $reports
            ->where('type', Safety::TYPE_REPORT)
            ->whereNotNull('user_id')
            ->select('id', 'user_id')
            ->chunkById(100, function (Collection $chunk) use ($title, $message) {
                $users = User::query()->withoutGlobalScopes()->whereIn('id', $chunk->pluck('user_id'))->get();
                Notification::send($users, new ModerationNotice($title, $message));
            });
    }

    public function label(Model $item): string
    {
        $name = $this->reportables->nameFor($item::class) ?? 'content';

        return $name === 'user' ? 'account' : $name;
    }

    public function reasonLabel(string $reason): string
    {
        return [
            'dmca' => 'a DMCA notice',
            'copyright' => 'copyright infringement',
            'spam' => 'spam',
            'hate' => 'hateful content',
            'abuse' => 'abuse or harassment',
            'violent' => 'violent content',
            'privacy' => 'a privacy concern',
            'sensitive' => 'sensitive media',
            'self-harm' => 'self-harm content',
            'child-safety' => 'child safety',
        ][$reason] ?? $reason;
    }
}
