<?php

namespace App\Enums;

use App\Services\Ai\AiSettings;

/**
 * How a music video is rendered. Advanced uses higher-end lip sync and b-roll
 * models at its own credit price, and uses plan video minutes faster.
 */
enum VideoQuality: string
{
    case STANDARD = 'standard';
    case ADVANCED = 'advanced';

    /**
     * The model that makes the singing shots.
     */
    public function singer(): VideoCandidate
    {
        return match ($this) {
            self::STANDARD => VideoCandidate::PRUNA_AVATAR,
            self::ADVANCED => AiSettings::videoAdvancedSingerModel(),
        };
    }

    /**
     * The model that animates the shots between the singing.
     */
    public function broll(): VideoCandidate
    {
        return match ($this) {
            self::STANDARD => AiSettings::videoBrollModel(),
            self::ADVANCED => AiSettings::videoAdvancedBrollModel(),
        };
    }

    /**
     * Credits rendering uses per started minute of the song.
     */
    public function credits(): int
    {
        return match ($this) {
            self::STANDARD => AiSettings::videoCredits(),
            self::ADVANCED => AiSettings::videoAdvancedCredits(),
        };
    }

    /**
     * Plan video minutes one minute of rendering uses.
     */
    public function minuteRate(): int
    {
        return match ($this) {
            self::STANDARD => 1,
            self::ADVANCED => AiSettings::videoAdvancedMinuteRate(),
        };
    }
}
