<?php

namespace App\Services\Ai\Tasks;

use App\Enums\AiEngine;
use App\Enums\VideoCandidate;
use App\Models\AiJob;
use App\Models\MusicVideo;
use App\Models\MusicVideoShot;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Replicate\ReplicateBatch;
use App\Services\Ai\Replicate\ReplicateClient;
use App\Services\Ai\Replicate\ReplicateException;
use App\Services\Ai\Stems\DemucsSplitter;
use App\Services\Ai\Video\KeyframeDrawer;
use App\Services\Ai\Video\VideoStorage;
use App\Services\Audio\StemAudio;
use App\Services\Audio\TrackAudio;
use App\Services\Video\VideoFiles;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * Renders a music video from its storyboard: singing shots lip-synced to the
 * song's vocal, b-roll animated from its still, all cut together over the
 * song. Shots already rendered from the same still are kept.
 */
class RenderVideo implements AiTask, HandlesFailure
{
    /** The model that makes the singing shots */
    public const SINGER = VideoCandidate::PRUNA_AVATAR;

    /** Clips rendered at once */
    protected const PARALLEL = 5;

    /** Seconds one clip may take */
    protected const TIMEOUT = 900;

    protected const SING_PROMPT = 'The singer performs this line to the camera with feeling, natural head and shoulder movement.';

    public function __construct(
        protected TrackAudio $tracks,
        protected StemAudio $audio,
        protected DemucsSplitter $splitter,
        protected KeyframeDrawer $keyframes,
        protected VideoStorage $storage,
        protected VideoFiles $video,
    ) {}

    /**
     * The longest shot the chosen b-roll model renders whole.
     */
    public static function maxShotSeconds(): float
    {
        return min(8.0, AiSettings::videoBrollModel()->length(60));
    }

    /**
     * The full price per started minute; clips kept from an earlier render
     * aren't paid for again, so a render pays only the share still to make.
     */
    public function credits(array $input): int
    {
        if (($input['billing'] ?? null) === MusicVideo::MINUTES) {
            return 0;
        }

        $credits = AiSettings::videoCredits() * max(1, (int) ($input['minutes'] ?? 1));
        $total = (int) ($input['shots'] ?? 0);

        if ($total <= 0) {
            return $credits;
        }

        return max(1, (int) ceil($credits * (int) ($input['missing'] ?? $total) / $total));
    }

    public function describe(array $input): string
    {
        return 'Music video: ' . ($input['title'] ?? 'track');
    }

    public function engine(array $input): AiEngine
    {
        return AiEngine::REPLICATE;
    }

    public function handle(AiJob $job): array
    {
        $video = MusicVideo::query()
            ->with(['track', 'shots'])
            ->findOrFail($job->input['music_video_id']);

        $song = $this->tracks->local($video->track, true) ?? throw new RuntimeException('The track has no audio.');
        $files = [$song];

        try {
            $todo = $video->shots->filter(fn(MusicVideoShot $shot) => ! $shot->clip);
            $vocals = $todo->contains('kind', MusicVideoShot::PERFORMANCE) ? $this->vocals($song) : null;
            $files[] = $vocals;

            $this->renderShots($video, $todo, $vocals);
            $video->load('shots');
            $this->assemble($video, $song);
        } finally {
            array_map(fn(?string $file) => $file && @unlink($file), $files);
        }

        return ['music_video_id' => $video->id, 'shots' => $video->shots->count()];
    }

    /**
     * Join a finished video's clips over its song again, without rendering
     * or charging for any shot.
     */
    public function restitch(MusicVideo $video): void
    {
        $video->load(['track', 'shots']);

        $song = $this->tracks->local($video->track, true) ?? throw new RuntimeException('The track has no audio.');

        try {
            $this->assemble($video, $song);
        } finally {
            @unlink($song);
        }
    }

    /**
     * Cut the shots' clips together over the song and keep the result as the
     * video's file.
     */
    protected function assemble(MusicVideo $video, string $song): void
    {
        $clips = $video->shots
            ->map(fn(MusicVideoShot $shot) => [$this->storage->local($shot->clip), $shot->length()])
            ->all();
        $files = array_filter(array_column($clips, 0));

        try {
            if (count($files) !== count($clips)) {
                throw new RuntimeException('A rendered shot is missing.');
            }

            $stitched = $this->video->stitch($clips, $song, $video->size());
            $files[] = $stitched;

            $old = $video->file;
            $file = $this->storage->put($video, $stitched, 'video', 'mp4');

            $video
                ->forceFill(['file' => $file, 'status' => MusicVideo::READY, 'error' => null, 'finished_at' => now()])
                ->save();

            $this->storage->delete($old);
        } finally {
            array_map(fn(string $file) => @unlink($file), $files);
        }
    }

    public function failed(AiJob $job): void
    {
        MusicVideo::query()
            ->whereKey($job->input['music_video_id'] ?? null)
            ->update([
                'status' => MusicVideo::STORYBOARD,
                'billing' => null,
                'minutes' => 0,
                'billed_at' => null,
                'error' => 'The video couldn\'t be rendered. Your credits or minutes were given back.',
            ]);
    }

    /**
     * Render each shot from its still and keep the clips. Shots that fail
     * get one more try before the render gives up.
     *
     * @param  Collection<int, MusicVideoShot>  $shots
     */
    protected function renderShots(MusicVideo $video, Collection $shots, ?string $vocals): void
    {
        $client = ReplicateClient::make();
        $runs = [];
        $temp = [];

        try {
            foreach ($shots as $shot) {
                $image = $this->keyframes->upload($shot->keyframe) ?? throw new RuntimeException("Shot {$shot->position} has no still.");

                if ($shot->kind === MusicVideoShot::PERFORMANCE) {
                    $cut = $this->audio->cut($vocals, $shot->start, $shot->end);
                    $temp[] = $cut;
                    $audio = $client->upload($cut, 'audio/mpeg');
                    $runs[$shot->id] = [self::SINGER->model(), self::SINGER->input($image, $audio, self::SING_PROMPT . ' ' . $shot->prompt, $shot->length())];
                } else {
                    $model = AiSettings::videoBrollModel();
                    $runs[$shot->id] = [$model->model(), $model->input($image, null, $shot->prompt, $shot->length())];
                }
            }
        } finally {
            array_map(fn(string $file) => @unlink($file), $temp);
        }

        $batch = ReplicateBatch::make();

        foreach ([1, 2] as $try) {
            $results = $batch->run($runs, self::PARALLEL, self::TIMEOUT);
            $failed = [];

            foreach ($results as $id => $result) {
                if ($result instanceof Throwable) {
                    $failed[$id] = $result;

                    continue;
                }

                $this->keep($video, $shots->firstWhere('id', $id), $result['output']);
            }

            if (! $failed) {
                return;
            }

            if ($try === 2) {
                throw reset($failed);
            }

            $runs = array_intersect_key($runs, $failed);
        }
    }

    protected function keep(MusicVideo $video, MusicVideoShot $shot, mixed $output): void
    {
        $url = is_array($output) ? reset($output) : $output;

        if (! ReplicateClient::isFile($url)) {
            throw new ReplicateException('The video model returned no clip.');
        }

        $local = ReplicateClient::make()->download($url);

        try {
            $old = $shot->clip;
            $file = $this->storage->put($video, $local, "clip-{$shot->position}", 'mp4');

            $shot
                ->forceFill(['clip' => $file])
                ->save();

            $this->storage->delete($old);
        } finally {
            @unlink($local);
        }
    }

    /**
     * The song's vocal, split out with the same model the studio uses.
     */
    protected function vocals(string $song): string
    {
        $parts = $this->splitter->split($song);
        $vocals = $parts['vocals'] ?? throw new ReplicateException('Stem separation found no vocals in the song.');

        foreach ($parts as $name => $file) {
            if ($name !== 'vocals') {
                @unlink($file);
            }
        }

        return $vocals;
    }
}
