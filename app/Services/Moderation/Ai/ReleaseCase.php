<?php

namespace App\Services\Moderation\Ai;

use App\Models\Release;
use App\Models\Safety;
use App\Models\Takedown;
use App\Models\Track;
use App\Services\Releases\ReleaseVersions;

/**
 * @property Release $subject
 */
class ReleaseCase extends ReviewCase
{
    /** Tracks described in the brief */
    public const TRACKS = 30;

    public function __construct(Release $release, protected ReleaseVersions $versions)
    {
        parent::__construct($release);
    }

    public function label(): string
    {
        return 'release';
    }

    public function isPending(): bool
    {
        return $this->subject->isInReview();
    }

    public function guidelines(): string
    {
        return <<<'TEXT'
            A creator published a release on a music platform and it waits for approval before going live.
            Approve releases that look like genuine music from this creator with complete, sensible details.
            Send it back when the details are missing, spammy or misleading (titles stuffed with keywords or other
            artists' names, placeholder text), when tracks are flagged as copies of other creators' tracks, when the
            lyrics use words flagged for review in a hateful or abusive way, when explicit lyrics are not marked
            explicit, or when the artwork is sexual, violent or hateful.
            When sending it back, say exactly what the creator should change.
            TEXT;
    }

    public function brief(): string
    {
        $release = $this->subject;
        $artist = $release->artist()->withoutGlobalScopes()->first();
        $owner = $release->user()->withoutGlobalScopes()->first();

        $tracks = Track::query()
            ->withoutGlobalScopes()
            ->where('release_id', $release->id)
            ->with('lyric')
            ->orderBy('id')
            ->limit(self::TRACKS)
            ->get();

        $flags = Safety::query()
            ->pending()
            ->where('model_type', (new Track)->getMorphClass())
            ->whereIn('model_id', $tracks->modelKeys())
            ->get(['model_id', 'type', 'details'])
            ->groupBy('model_id');

        $trackLines = $tracks->map(function (Track $track) use ($flags) {
            $lyric = $track->lyric;
            $line = sprintf(
                '- "%s", %ss, marked explicit: %s',
                $track->title,
                (int) $track->duration,
                $track->explicit ? 'yes' : 'no',
            );

            if ($lyric) {
                $line .= "\n  Lyrics" . ($lyric->explicit ? ' (contain explicit words)' : '') . ': ' . $this->clip($lyric->text, 500);

                if ($lyric->flagged) {
                    $line .= "\n  Words flagged for review: " . implode(', ', (array) $lyric->flagged);
                }
            }

            foreach ($flags->get($track->id, []) as $flag) {
                $line .= "\n  Open {$flag->type} flag: {$flag->details}";
            }

            return $line;
        })->join("\n") ?: '- (no tracks)';

        $takedowns = $owner ? Takedown::query()->inForce()->whereIn('model_id', Release::query()->withoutGlobalScopes()->where('user_id', $owner->id)->select('id'))
            ->where('model_type', $release->getMorphClass())->count() : 0;

        return implode("\n", [
            'RELEASE',
            "Title: {$release->title}" . ($release->version ? " ({$release->version})" : ''),
            'Artist: ' . ($artist?->name ?? 'unknown'),
            'Genre: ' . ($release->genre()->value('title') ?? 'unknown'),
            'Marked explicit: ' . ($release->explicit ? 'yes' : 'no'),
            'Description: ' . $this->clip($release->description, 600),
            'Record label: ' . ($release->record_label ?: 'none'),
            'Publisher: ' . ($release->publisher ?: 'none'),
            'Composer: ' . ($release->composer ?: 'none'),
            'P line: ' . ($release->pline ?: 'none'),
            'Price: ' . ((float) $release->price > 0 ? $release->price : 'free'),
            '',
            'CREATOR',
            'Account: @' . ($owner?->username ?? 'unknown') . ', joined ' . ($owner?->created_at?->toDateString() ?? 'unknown'),
            "Their releases taken down by moderators: {$takedowns}",
            '',
            'TRACKS',
            $trackLines,
        ]);
    }

    public function image(): ?string
    {
        $url = $this->subject->files['artwork'] ?? null;

        return $this->subject->image && is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }

    public function approve(?string $note): void
    {
        $this->versions->approve($this->subject, null);
    }

    public function reject(string $note): void
    {
        $this->versions->reject($this->subject, null, $note);
    }
}
