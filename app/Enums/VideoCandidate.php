<?php

namespace App\Enums;

/**
 * A video model tried by the music video trial. Singing models turn a portrait
 * and a vocal into a performance; motion models turn a still into b-roll.
 * Costs are Replicate's list prices in USD.
 */
enum VideoCandidate: string
{
    case WAN_S2V = 'wan-s2v';
    case PRUNA_AVATAR = 'pruna-avatar';
    case OMNI_HUMAN = 'omni-human';
    case SEEDANCE_FAST = 'seedance-fast';
    case WAN_I2V_FAST = 'wan-i2v-fast';
    case P_VIDEO = 'p-video';

    /** Frames and frame rate Wan 2.2 i2v fast renders at its flat price */
    protected const WAN_FRAMES = 81;

    protected const WAN_FPS = 16;

    public function model(): string
    {
        return match ($this) {
            self::WAN_S2V => 'wan-video/wan-2.2-s2v',
            self::PRUNA_AVATAR => 'prunaai/p-video-2-pro-avatar',
            self::OMNI_HUMAN => 'bytedance/omni-human',
            self::SEEDANCE_FAST => 'bytedance/seedance-1-pro-fast',
            self::WAN_I2V_FAST => 'wan-video/wan-2.2-i2v-fast',
            self::P_VIDEO => 'prunaai/p-video',
        };
    }

    /**
     * Whether the model lip-syncs a portrait to the vocal.
     */
    public function sings(): bool
    {
        return in_array($this, [self::WAN_S2V, self::PRUNA_AVATAR, self::OMNI_HUMAN], true);
    }

    /**
     * How many seconds of video the model makes for a window of this length.
     */
    public function length(float $seconds): float
    {
        return match ($this) {
            self::SEEDANCE_FAST => (float) min(12, max(2, (int) round($seconds))),
            self::P_VIDEO => (float) min(20, max(1, (int) round($seconds))),
            self::WAN_I2V_FAST => self::WAN_FRAMES / self::WAN_FPS,
            default => $seconds,
        };
    }

    /**
     * The expected cost in USD of one run over a window of this length.
     */
    public function cost(float $seconds): float
    {
        $length = $this->length($seconds);

        return round(match ($this) {
            self::WAN_S2V => 0.02 * $length,
            self::PRUNA_AVATAR => 0.04 * $length,
            self::OMNI_HUMAN => 0.14 * $length,
            self::SEEDANCE_FAST => 0.025 * $length,
            self::WAN_I2V_FAST => 0.11,
            self::P_VIDEO => 0.02 * $length,
        }, 3);
    }

    /**
     * The model's input for a portrait or still, the vocal for singing models,
     * a prompt and the window's length.
     *
     * @return array<string, mixed>
     */
    public function input(string $image, ?string $vocals, string $prompt, float $seconds): array
    {
        $length = $this->length($seconds);

        return match ($this) {
            self::WAN_S2V => ['image' => $image, 'audio' => $vocals, 'prompt' => $prompt],
            self::PRUNA_AVATAR => ['image' => $image, 'audio' => $vocals, 'prompt' => $prompt, 'resolution' => '768p'],
            self::OMNI_HUMAN => ['image' => $image, 'audio' => $vocals],
            self::SEEDANCE_FAST => ['image' => $image, 'prompt' => $prompt, 'duration' => (int) $length, 'resolution' => '720p'],
            self::WAN_I2V_FAST => ['image' => $image, 'prompt' => $prompt, 'resolution' => '720p', 'num_frames' => self::WAN_FRAMES],
            self::P_VIDEO => ['image' => $image, 'prompt' => $prompt, 'duration' => (int) $length, 'resolution' => '720p', 'save_audio' => false],
        };
    }
}
