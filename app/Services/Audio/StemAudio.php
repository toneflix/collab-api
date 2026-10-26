<?php

namespace App\Services\Audio;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * FFmpeg work on stem audio: rebuilding the part of a song the other stems
 * leave out, cutting and splicing sections, reading levels and encoding once
 * at a high bitrate.
 */
class StemAudio
{
    /** Bitrate stems are stored at, in kbps */
    public const BITRATE = 320;

    /** Sample rate the remainder is worked out at */
    protected const RATE = 44100;

    public function ffmpeg(): string
    {
        return config('laravel-ffmpeg.ffmpeg.binaries') ?: 'ffmpeg';
    }

    /**
     * The song minus the given parts, as a 32 bit float WAV, so the parts and
     * the remainder add up to exactly the song.
     *
     * @param  list<string>  $parts
     */
    public function remainder(string $song, array $parts): string
    {
        $out = $this->temp('wav');
        $inputs = ['-i', $song];
        $chains = ["[0:a]{$this->format()}[s]"];
        $mix = '[s]';

        foreach (array_values($parts) as $i => $part) {
            $n = $i + 1;
            $inputs = [...$inputs, '-i', $part];
            $chains[] = "[{$n}:a]{$this->format()},volume=-1[p{$n}]";
            $mix .= "[p{$n}]";
        }

        $count = count($parts) + 1;
        $chains[] = "{$mix}amix=inputs={$count}:normalize=0:duration=first:dropout_transition=0[out]";

        $this->run([
            ...$inputs,
            '-filter_complex', implode(';', $chains),
            '-map', '[out]', '-c:a', 'pcm_f32le', $out,
        ], $out);

        return $out;
    }

    /**
     * Encode audio as a 320 kbps MP3, turned up or down by a gain in dB.
     */
    public function mp3(string $path, float $gain = 0.0): string
    {
        $out = $this->temp('mp3');
        $volume = $gain != 0.0 ? ['-af', 'volume=' . round($gain, 4) . 'dB'] : [];

        $this->run(['-i', $path, ...$volume, '-c:a', 'libmp3lame', '-b:a', self::BITRATE . 'k', $out], $out);

        return $out;
    }

    /**
     * Encode audio as a small 16 kHz mono MP3, enough for speech recognition.
     */
    public function speech(string $path): string
    {
        $out = $this->temp('mp3');

        $this->run(['-i', $path, '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'libmp3lame', '-b:a', '64k', $out], $out);

        return $out;
    }

    /**
     * A part of the audio, between two times in seconds, as a 320 kbps MP3.
     */
    public function cut(string $path, float $from, float $to): string
    {
        $out = $this->temp('mp3');

        $this->run([
            '-i', $path,
            '-af', "{$this->format()},atrim=start={$this->time($from)}:end={$this->time($to)},asetpts=PTS-STARTPTS",
            '-c:a', 'libmp3lame', '-b:a', self::BITRATE . 'k', $out,
        ], $out);

        return $out;
    }

    /**
     * A part of the audio stretched or squeezed in time, keeping its pitch,
     * to exactly this many seconds, as a 32 bit float WAV.
     */
    public function fit(string $path, float $from, float $to, float $length): string
    {
        $tempo = min(2, max(0.5, ($to - $from) / $length));
        $stretch = abs($tempo - 1) > 0.001 ? ',atempo=' . round($tempo, 6) : '';
        $samples = (int) round($length * self::RATE);

        return $this->wav([
            '-i', $path,
            '-af', "{$this->format()},atrim=start={$this->time($from)}:end={$this->time($to)},asetpts=PTS-STARTPTS{$stretch},apad,atrim=end_sample={$samples}",
        ]);
    }

    /**
     * The audio with the part between two times swapped for the same part
     * of a section, crossfaded at each end. The section starts at
     * `$sectionStart` seconds into the audio and covers the part plus the
     * fades. Returns a 32 bit float WAV as long as the audio.
     */
    public function splice(string $path, string $section, float $sectionStart, float $from, float $to, float $duration, float $fade): string
    {
        $fadeIn = $from > 0 ? min($fade, $from) : 0.0;
        $fadeOut = $to < $duration ? min($fade, $duration - $to) : 0.0;
        $parts = [];
        $chains = [];

        $piece = "[1:a]{$this->format()},atrim=start={$this->time($from - $sectionStart)}:end={$this->time($to + $fadeOut - $sectionStart)},asetpts=PTS-STARTPTS";
        $piece .= $fadeIn > 0 ? ",afade=t=in:d={$this->time($fadeIn)}" : '';
        $piece .= $fadeOut > 0 ? ",afade=t=out:st={$this->time($to - $from)}:d={$this->time($fadeOut)}" : '';
        $chains[] = "{$piece},{$this->delay($from)}[section]";
        $parts[] = '[section]';

        if ($fadeIn > 0) {
            $chains[] = "[0:a]{$this->format()},atrim=end={$this->time($from + $fadeIn)},asetpts=PTS-STARTPTS,afade=t=out:st={$this->time($from)}:d={$this->time($fadeIn)}[head]";
            $parts[] = '[head]';
        }

        if ($fadeOut > 0) {
            $chains[] = "[2:a]{$this->format()},atrim=start={$this->time($to)},asetpts=PTS-STARTPTS,afade=t=in:d={$this->time($fadeOut)},{$this->delay($to)}[tail]";
            $parts[] = '[tail]';
        }

        $samples = (int) round($duration * self::RATE);
        $chains[] = implode('', $parts) . 'amix=inputs=' . count($parts) . ":normalize=0:duration=longest:dropout_transition=0,apad,atrim=end_sample={$samples}[out]";

        return $this->wav([
            '-i', $path, '-i', $section, '-i', $path,
            '-filter_complex', implode(';', $chains),
            '-map', '[out]',
        ]);
    }

    /**
     * Render FFmpeg arguments into a 32 bit float WAV.
     *
     * @param  list<string>  $args
     */
    public function wav(array $args): string
    {
        $out = $this->temp('wav');

        $this->run([...$args, '-c:a', 'pcm_f32le', $out], $out);

        return $out;
    }

    /**
     * The file's RMS level in dBFS, or null when it can't be read.
     */
    public function level(string $path): ?float
    {
        return $this->stat($path, 'RMS_level', 'RMS level dB');
    }

    /**
     * The file's loudest sample in dBFS, or null when it can't be read.
     */
    public function peak(string $path): ?float
    {
        return $this->stat($path, 'Peak_level', 'Peak level dB');
    }

    /**
     * The file's channel count and length in seconds.
     *
     * @return array{channels: int, duration: float}
     */
    public function probe(string $path): array
    {
        $process = new Process([$this->ffmpeg(), '-hide_banner', '-i', $path]);
        $process->setTimeout(60);
        $process->run();
        $info = $process->getErrorOutput();

        $duration = preg_match('/Duration:\s*(\d+):(\d+):([\d.]+)/', $info, $time)
            ? $time[1] * 3600 + $time[2] * 60 + (float) $time[3]
            : 0.0;

        return [
            'channels' => preg_match('/Audio:[^\n]*\b(mono|1 channels)\b/', $info) ? 1 : 2,
            'duration' => $duration,
        ];
    }

    protected function stat(string $path, string $measure, string $label): ?float
    {
        $process = new Process([
            $this->ffmpeg(), '-hide_banner', '-nostats', '-i', $path,
            '-af', "astats=measure_perchannel=none:measure_overall={$measure}", '-f', 'null', '-',
        ]);
        $process->setTimeout(300);
        $process->run();

        if (! preg_match('/' . preg_quote($label, '/') . ':\s*(-?[\d.]+|-inf)/', $process->getErrorOutput(), $match)) {
            return null;
        }

        return $match[1] === '-inf' ? -INF : (float) $match[1];
    }

    /**
     * Seconds as FFmpeg takes them, with no exponent.
     */
    protected function time(float $seconds): string
    {
        return number_format(max(0, $seconds), 6, '.', '');
    }

    /**
     * A filter that starts audio this many seconds in, to the sample.
     */
    protected function delay(float $seconds): string
    {
        return 'adelay=delays=' . (int) round($seconds * self::RATE) . 'S:all=1';
    }

    protected function format(): string
    {
        return 'aresample=' . self::RATE . ',aformat=sample_fmts=fltp:channel_layouts=stereo';
    }

    /**
     * @param  list<string>  $args
     */
    protected function run(array $args, string $out): void
    {
        $process = new Process([$this->ffmpeg(), '-hide_banner', '-loglevel', 'error', '-y', ...$args]);
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($out) || filesize($out) === 0) {
            @unlink($out);

            throw new RuntimeException('FFmpeg failed: ' . trim($process->getErrorOutput()));
        }
    }

    protected function temp(string $ext): string
    {
        $base = tempnam(sys_get_temp_dir(), 'stem-');
        @unlink($base);

        return "{$base}.{$ext}";
    }
}
