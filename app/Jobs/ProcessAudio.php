<?php

namespace App\Jobs;

use App\Models\Track;
use App\Services\Audio\Aac;
use FFMpeg\Format\Audio\Flac;
use FFMpeg\Format\Audio\Mp3;
use FFMpeg\Format\Audio\Vorbis;
use FFMpeg\Format\Audio\Wav;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\Exporters\EncodingException;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use Toneflix\LaravelFileable\Media;

class ProcessAudio implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected static $outputMap = [
        'aac' => Aac::class,
        'flac' => Flac::class,
        'mp3' => Mp3::class,
        'wav' => Wav::class,
        'vorbis' => Vorbis::class,
    ];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Track $track
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->check()) {
            $format = static::$outputMap[config('settings.encoding.output')] ?? null;

            if (! class_exists($format)) {
                $this->track->meta['errors'] = [
                    'message' => 'Invalid format provided for settings.encoding.output',
                ];
                $this->track->saveQuietly();

                return;
            }

            /** @var \FFMpeg\Format\Audio\Aac|Flac|Mp3|Wav|Vorbis */
            $outputFormat = (new $format);

            // Use a custom codec when one is configured
            if ($codec = config('settings.encoding.output_codec')) {
                $outputFormat->setAudioCodec($codec);
            }

            // Set the output channels
            $outputFormat->setAudioChannels(2);

            // Set the kilobitrate
            $outputFormat->setAudioKiloBitrate(config('settings.encoding.output_bitrate', 256));

            try {
                // Get the current path
                $path = $this->track->get_files['audio']['path'];

                // Generate a random name for the new file
                $new_name = rand() . '_' . rand() . '.' . config('settings.encoding.output');

                // Set the old file name to delete it later
                $old_name = $this->track->audio;

                // Set the new path
                $new_path = pathinfo($path, PATHINFO_DIRNAME) . '/' . $new_name;

                // Open the file
                $instance = FFMpeg::open($path);

                // Convert and export
                $instance
                    ->export()
                    ->inFormat($outputFormat)
                    ->onProgress(function ($percentage) {
                        // Log the progress if allowed
                        if (config('settings.encoding.enable_progress_logs', false)) {
                            $this->track->meta['progress'] = $percentage;
                            $this->track->saveQuietly();
                        }
                    })
                    ->save($new_path);

                // Update the file
                $this->track->audio = $new_name;
                $this->track->duration = $instance->getDurationInSeconds();
                $this->track->meta['processed'] = true;
                $this->track->meta['format'] = config('settings.encoding.output');
                $this->track->saveQuietly();

                // Delete the old file
                (new Media)->delete($this->track->file_field['audio'], $old_name);
            } catch (EncodingException $e) {
                $command = $e->getCommand();
                $errorLog = $e->getErrorOutput();
                $this->track->meta['errors'] = [
                    'message' => $e->getMessage(),
                    'command' => $command,
                    'errorLog' => $errorLog,
                ];
                $this->track->saveQuietly();
            }
        }
    }

    protected function check(): bool
    {
        $processed = $this->track->meta['processed'] ?? false;
        $path = $this->track->get_files['audio']['path'];
        $ext = pathinfo($path, PATHINFO_EXTENSION);

        if (
            ! $processed &&
            config('settings.encoding.enabled', false) &&
            in_array($ext, config('settings.encoding.formats', [])) &&
            Storage::exists($this->track->get_files['audio']['path'])
        ) {
            return true;
        }

        return false;
    }
}
