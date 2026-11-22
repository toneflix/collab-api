<?php

namespace App\Enums;

/**
 * A video model tried by the music video trial. Singing models turn a portrait
 * and a vocal into a performance; motion models turn a still into b-roll.
 * Advanced models cost several times more and are kept for a higher tier.
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
    case OMNI_HUMAN_15 = 'omni-human-1.5';
    case KLING_AVATAR = 'kling-avatar';
    case FABRIC = 'fabric';
    case LIPSYNC_2_PRO = 'lipsync-2-pro';
    case KLING_V3 = 'kling-v3';
    case VEO_FAST = 'veo-3.1-fast';
    case SEEDANCE_2 = 'seedance-2';

    /** Frames and frame rate Wan 2.2 i2v fast renders at its flat price */
    protected const WAN_FRAMES = 81;

    protected const WAN_FPS = 16;

    /** Clip lengths Veo 3.1 Fast renders */
    protected const VEO_LENGTHS = [4, 6, 8];

    public function model(): string
    {
        return match ($this) {
            self::WAN_S2V => 'wan-video/wan-2.2-s2v',
            self::PRUNA_AVATAR => 'prunaai/p-video-2-pro-avatar',
            self::OMNI_HUMAN => 'bytedance/omni-human',
            self::SEEDANCE_FAST => 'bytedance/seedance-1-pro-fast',
            self::WAN_I2V_FAST => 'wan-video/wan-2.2-i2v-fast',
            self::P_VIDEO => 'prunaai/p-video',
            self::OMNI_HUMAN_15 => 'bytedance/omni-human-1.5',
            self::KLING_AVATAR => 'kwaivgi/kling-avatar-v2',
            self::FABRIC => 'veed/fabric-1.0',
            self::LIPSYNC_2_PRO => 'sync/lipsync-2-pro',
            self::KLING_V3 => 'kwaivgi/kling-v3-video',
            self::VEO_FAST => 'google/veo-3.1-fast',
            self::SEEDANCE_2 => 'bytedance/seedance-2.0',
        };
    }

    /**
     * The candidates of one tier, standard or advanced.
     *
     * @return list<self>
     */
    public static function tier(bool $advanced): array
    {
        return array_values(array_filter(
            self::cases(),
            fn(self $candidate) => $candidate->advanced() === $advanced,
        ));
    }

    /**
     * The b-roll models admins can pick, with their model names.
     *
     * @return array<string, string>
     */
    public static function brollOptions(): array
    {
        return collect(self::tier(false))
            ->reject(fn(self $candidate) => $candidate->sings())
            ->mapWithKeys(fn(self $candidate) => [$candidate->value => $candidate->model()])
            ->all();
    }

    /**
     * The advanced lip sync or b-roll models admins can pick, with their
     * model names. Models that dub a clip are left out.
     *
     * @return array<string, string>
     */
    public static function advancedOptions(bool $sings): array
    {
        return collect(self::tier(true))
            ->filter(fn(self $candidate) => $candidate->sings() === $sings && ! $candidate->source())
            ->mapWithKeys(fn(self $candidate) => [$candidate->value => $candidate->model()])
            ->all();
    }

    /**
     * Whether the model lip-syncs a portrait to the vocal.
     */
    public function sings(): bool
    {
        return in_array($this, [
            self::WAN_S2V,
            self::PRUNA_AVATAR,
            self::OMNI_HUMAN,
            self::OMNI_HUMAN_15,
            self::KLING_AVATAR,
            self::FABRIC,
            self::LIPSYNC_2_PRO,
        ], true);
    }

    /**
     * Whether the model belongs to the advanced tier.
     */
    public function advanced(): bool
    {
        return in_array($this, [
            self::OMNI_HUMAN_15,
            self::KLING_AVATAR,
            self::FABRIC,
            self::LIPSYNC_2_PRO,
            self::KLING_V3,
            self::VEO_FAST,
            self::SEEDANCE_2,
        ], true);
    }

    /**
     * The model that first renders the clip this one lip-syncs, for models
     * that dub a video rather than animate a still.
     */
    public function source(): ?self
    {
        return $this === self::LIPSYNC_2_PRO ? self::P_VIDEO : null;
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
            self::KLING_V3 => (float) min(15, max(3, (int) ceil($seconds))),
            self::SEEDANCE_2 => (float) min(15, max(4, (int) ceil($seconds))),
            self::VEO_FAST => (float) (collect(self::VEO_LENGTHS)->first(fn(int $length) => $length >= $seconds) ?? max(self::VEO_LENGTHS)),
            self::LIPSYNC_2_PRO => $this->source()->length($seconds),
            default => $seconds,
        };
    }

    /**
     * The expected cost in USD of one run over a window of this length,
     * with the clip a dubbing model starts from.
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
            self::OMNI_HUMAN_15 => 0.16 * $length,
            self::KLING_AVATAR => 0.056 * $length,
            self::FABRIC => 0.15 * $length,
            self::LIPSYNC_2_PRO => 0.08325 * $length + $this->source()->cost($seconds),
            self::KLING_V3 => 0.168 * $length,
            self::VEO_FAST => 0.10 * $length,
            self::SEEDANCE_2 => 0.18 * $length,
        }, 3);
    }

    /**
     * The model's input for a portrait or still, the vocal for singing models,
     * a prompt and the window's length. Dubbing models read the source clip
     * instead of the still.
     *
     * @return array<string, mixed>
     */
    public function input(string $image, ?string $vocals, string $prompt, float $seconds, ?string $video = null): array
    {
        $length = $this->length($seconds);

        return match ($this) {
            self::WAN_S2V => ['image' => $image, 'audio' => $vocals, 'prompt' => $prompt],
            self::PRUNA_AVATAR => ['image' => $image, 'audio' => $vocals, 'prompt' => $prompt, 'resolution' => '768p'],
            self::OMNI_HUMAN => ['image' => $image, 'audio' => $vocals],
            self::SEEDANCE_FAST => ['image' => $image, 'prompt' => $prompt, 'duration' => (int) $length, 'resolution' => '720p'],
            self::WAN_I2V_FAST => ['image' => $image, 'prompt' => $prompt, 'resolution' => '720p', 'num_frames' => self::WAN_FRAMES],
            self::P_VIDEO => ['image' => $image, 'prompt' => $prompt, 'duration' => (int) $length, 'resolution' => '720p', 'save_audio' => false],
            self::OMNI_HUMAN_15 => ['image' => $image, 'audio' => $vocals, 'prompt' => $prompt],
            self::KLING_AVATAR => ['image' => $image, 'audio' => $vocals, 'prompt' => $prompt, 'mode' => 'std'],
            self::FABRIC => ['image' => $image, 'audio' => $vocals, 'resolution' => '720p'],
            self::LIPSYNC_2_PRO => ['video' => $video, 'audio' => $vocals, 'sync_mode' => 'cut_off'],
            self::KLING_V3 => ['start_image' => $image, 'prompt' => $prompt, 'duration' => (int) $length, 'mode' => 'standard', 'generate_audio' => false],
            self::VEO_FAST => ['image' => $image, 'prompt' => $prompt, 'duration' => (int) $length, 'resolution' => '720p', 'generate_audio' => false],
            self::SEEDANCE_2 => ['image' => $image, 'prompt' => $prompt, 'duration' => (int) $length, 'resolution' => '720p', 'aspect_ratio' => 'adaptive', 'generate_audio' => false],
        };
    }
}
