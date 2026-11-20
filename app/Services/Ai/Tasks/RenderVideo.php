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
use App\Services\Ai\Video\EditList;
use App\Services\Ai\Video\KeyframeDrawer;
use App\Services\Ai\Video\ShotTakes;
use App\Services\Ai\Video\VideoStorage;
use App\Services\Audio\StemAudio;
use App\Services\Audio\TrackAudio;
use App\Services\Video\VideoFiles;
use App\Services\Video\VideoPart;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * Renders a music video from its storyboard: singing shots lip-synced to the
 * song's vocal, b-roll animated from its still, all cut together over the
 * song. Shots whose take still fits are not rendered again, so a save
 * after trims and moves only stitches.
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
        protected ShotTakes $takes,
        protected EditList $edits,
    ) {}

    /**
     * The longest shot the chosen b-roll model renders whole.
     */
    public static function maxShotSeconds(): float
    {
        return min(8.0, AiSettings::videoBrollModel()->length(60));
    }

    /**
     * A first render pays the full price per started minute of the song. A
     * later one pays for the seconds of shots that need a new clip, and a
     * save with none to make only stitches, for free.
     */
    public function credits(array $input): int
    {
        if (($input['billing'] ?? null) === MusicVideo::MINUTES) {
            return 0;
        }

        if (! empty($input['full'])) {
            return AiSettings::videoCredits() * max(1, (int) ($input['minutes'] ?? 1));
        }

        $seconds = (float) ($input['seconds'] ?? 0);

        if ($seconds <= 0) {
            return 0;
        }

        return max(1, (int) ceil(AiSettings::videoCredits() * $seconds / 60));
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
            ->with(['track', 'shots.take'])
            ->findOrFail($job->input['music_video_id']);

        $song = $this->tracks->local($video->track, true) ?? throw new RuntimeException('The track has no audio.');
        $files = [$song];

        try {
            $todo = $video->shots->filter(fn(MusicVideoShot $shot) => $shot->needsClip());
            $vocals = $todo->contains('kind', MusicVideoShot::PERFORMANCE) ? $this->vocals($song) : null;
            $files[] = $vocals;

            if ($todo->isNotEmpty()) {
                $this->renderShots($video, $todo, $vocals);
            }

            $billing = $job->input['billing'] ?? null;
            $paid = $billing ? ['billing' => $billing, 'billed_at' => $job->created_at] : [];

            $this->assemble($video, $song, $paid);
        } finally {
            array_map(fn(?string $file) => $file && @unlink($file), $files);
        }

        return ['music_video_id' => $video->id, 'shots' => $video->shots->count(), 'rendered' => $todo->count()];
    }

    /**
     * Join a finished video's shots over its song again, without rendering
     * or charging for any shot. Every shot must already have its clip.
     */
    public function restitch(MusicVideo $video): void
    {
        $video->load(['track', 'shots.take']);

        if ($video->shots->contains(fn(MusicVideoShot $shot) => $shot->needsClip())) {
            throw new RuntimeException('A shot needs a new clip; save the video from the editor.');
        }

        $song = $this->tracks->local($video->track, true) ?? throw new RuntimeException('The track has no audio.');

        try {
            $this->assemble($video, $song);
        } finally {
            @unlink($song);
        }
    }

    /**
     * Cut the shots together over the song and keep the result as the
     * video's file, with the edit list it was cut from.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function assemble(MusicVideo $video, string $song, array $extra = []): void
    {
        $parts = $this->edits->parts($video);
        $files = array_map(fn(VideoPart $part) => $part->path, $parts);

        try {
            $stitched = $this->video->stitch($parts, $song, $video->size());
            $files[] = $stitched;

            $old = $video->file;
            $file = $this->storage->put($video, $stitched, 'video', 'mp4');
            $cut = $this->edits->describe($video);

            $video
                ->forceFill([
                    ...$extra,
                    'file' => $file,
                    'cut' => $cut,
                    'status' => MusicVideo::READY,
                    'error' => null,
                    'finished_at' => now(),
                    'edited_at' => null,
                ])
                ->save();

            $this->storage->delete($old);
        } finally {
            array_map(fn(string $file) => @unlink($file), $files);
        }
    }

    /**
     * Put the video back as it was before the render: a stitched video stays
     * ready with its file, a new one goes back to its storyboard.
     */
    public function failed(AiJob $job): void
    {
        $video = MusicVideo::query()
            ->find($job->input['music_video_id'] ?? null);

        $video
            ?->forceFill([
                'status' => $video->file ? MusicVideo::READY : MusicVideo::STORYBOARD,
                'error' => 'The video couldn\'t be rendered. Your credits or minutes were given back.',
            ])
            ->save();
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
            $this->takes->keep($video, $shot, $local);
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
