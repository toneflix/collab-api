<?php

namespace App\Console\Commands;

use App\Enums\VideoCandidate;
use App\Models\Track;
use App\Services\Ai\Video\VideoTrial;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class TrialVideoModels extends Command
{
    protected $signature = '
        ai:video-trial
            {track : Released track id or slug}
            {--from= : Second the window starts at (default: the first sung line)}
            {--seconds=8 : Length of the window, 2 to 15 seconds}
            {--image= : Portrait or still as a local path or URL (default: the artist picture, else the artwork)}
            {--ratio=16:9 : Shape the still is cropped to}
            {--prompt= : What the clips should show}
            {--only=* : Models to run (default: all)}
            {--force : Run without asking to confirm the cost}
    ';

    protected $description = 'Render one window of a song through each music video model to compare them';

    /** Replicate's price of the Demucs split that gives singing models the vocal, in USD */
    protected const SPLIT_COST = 0.007;

    protected const SING_PROMPT = 'The artist sings this song to the camera with feeling, music video lighting, natural head and shoulder movement.';

    protected const MOTION_PROMPT = 'Cinematic music video shot of the artist, slow camera move, moody lighting.';

    public function handle(VideoTrial $trial): int
    {
        $track = $this->track();
        $candidates = $this->candidates();

        if (! $track || ! $candidates) {
            return self::INVALID;
        }

        $seconds = (float) min(15, max(2, (float) $this->option('seconds')));
        $sings = collect($candidates)->contains(fn(VideoCandidate $candidate) => $candidate->sings());
        $total = collect($candidates)->sum(fn(VideoCandidate $candidate) => $candidate->cost($seconds)) + ($sings ? self::SPLIT_COST : 0);

        $this->table(
            ['Model', 'Kind', 'Seconds', 'About (USD)'],
            collect($candidates)->map(fn(VideoCandidate $candidate) => [
                $candidate->model(),
                $candidate->sings() ? 'lip sync' : 'b-roll',
                $candidate->length($seconds),
                number_format($candidate->cost($seconds), 3),
            ])
        );

        if (! $this->option('force') && ! $this->confirm('This spends about $' . number_format($total, 2) . ' on Replicate. Go ahead?')) {
            return self::SUCCESS;
        }

        $this->info('Preparing the window' . ($sings ? ' and splitting out the vocal' : '') . '...');

        try {
            $window = $trial->prepare(
                $track,
                $this->option('from') !== null ? (float) $this->option('from') : null,
                $seconds,
                $this->option('image'),
                $this->ratio(),
                $sings,
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $folder = VideoTrial::DIRECTORY . "/{$track->id}/" . now()->format('Ymd-His');
        $rows = [];

        $this->line(sprintf('Window: %.1fs to %.1fs.', $window->from, $window->from + $window->seconds));

        try {
            foreach ($candidates as $candidate) {
                $this->info("Running {$candidate->model()}...");

                $started = microtime(true);
                $prompt = $this->option('prompt') ?: ($candidate->sings() ? self::SING_PROMPT : self::MOTION_PROMPT);

                try {
                    $file = $trial->render($candidate, $window, $prompt, $folder);
                    $result = $trial->url($file);
                } catch (Throwable $e) {
                    $result = 'Failed: ' . $e->getMessage();
                }

                $rows[] = [$candidate->value, round(microtime(true) - $started) . 's', $result];
            }
        } finally {
            $window->forget();
        }

        $this->table(['Model', 'Took', 'Clip'], $rows);

        return self::SUCCESS;
    }

    protected function track(): ?Track
    {
        $value = (string) $this->argument('track');

        $track = Track::query()
            ->withoutGlobalScopes()
            ->where(fn(Builder $q) => $q
                ->where('slug', $value)
                ->when(ctype_digit($value), fn(Builder $q) => $q->orWhere('id', (int) $value)))
            ->first();

        if (! $track) {
            $this->error('No track has that id or slug.');
        }

        return $track;
    }

    /**
     * @return list<VideoCandidate>
     */
    protected function candidates(): array
    {
        $only = (array) $this->option('only');

        if (! $only) {
            return VideoCandidate::cases();
        }

        $candidates = array_map(fn(string $key) => VideoCandidate::tryFrom($key), $only);

        if (in_array(null, $candidates, true)) {
            $keys = implode(', ', array_column(VideoCandidate::cases(), 'value'));

            $this->error("Models must be among: {$keys}.");

            return [];
        }

        return collect($candidates)
            ->unique(fn(VideoCandidate $candidate) => $candidate->value)
            ->values()
            ->all();
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function ratio(): array
    {
        [$width, $height] = array_pad(array_map('intval', explode(':', (string) $this->option('ratio'))), 2, 0);

        return $width > 0 && $height > 0 ? [$width, $height] : [16, 9];
    }
}
