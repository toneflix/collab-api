<?php

namespace Tests\Unit;

use App\Services\Audio\StemAudio;
use App\Services\Video\VideoFiles;
use App\Services\Video\VideoPart;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class VideoFilesTest extends TestCase
{
    /** @var list<string> */
    protected array $files = [];

    protected function tearDown(): void
    {
        array_map(fn(string $file) => @unlink($file), $this->files);

        parent::tearDown();
    }

    public function test_stitched_clips_stay_on_the_song_timeline(): void
    {
        $ffmpeg = app(StemAudio::class)->ffmpeg();

        $clip = $this->make($ffmpeg, ['-f', 'lavfi', '-i', 'testsrc2=size=160x90:rate=25', '-t', '4', '-pix_fmt', 'yuv420p'], 'mp4');
        $song = $this->make($ffmpeg, ['-f', 'lavfi', '-i', 'sine=d=60'], 'mp3');

        // Lengths that don't fall on whole frames, like beat cuts at 95 BPM.
        $parts = array_fill(0, 20, VideoPart::clip($clip, 2.53));

        $out = app(VideoFiles::class)->stitch($parts, $song, [160, 90]);
        $this->files[] = $out;

        $probe = new Process([
            config('laravel-ffmpeg.ffprobe.binaries') ?: 'ffprobe',
            '-v', 'error',
            '-select_streams', 'v',
            '-count_frames',
            '-show_entries', 'stream=nb_read_frames',
            '-of', 'csv=p=0',
            $out,
        ]);
        $probe->mustRun();

        $this->assertSame((int) round(20 * 2.53 * VideoFiles::FPS), (int) trim($probe->getOutput()));
    }

    /**
     * @param  list<string>  $args
     */
    protected function make(string $ffmpeg, array $args, string $extension): string
    {
        $file = tempnam(sys_get_temp_dir(), 'video-test-') . ".{$extension}";
        $this->files[] = $file;

        (new Process([$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', ...$args, $file]))->mustRun();

        return $file;
    }
}
