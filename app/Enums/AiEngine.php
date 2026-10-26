<?php

namespace App\Enums;

use App\Services\Ai\AiSettings;

/**
 * A service the AI tools run on. Each engine has its own `.env` key group in
 * AiSettings::KEYS.
 */
enum AiEngine: string
{
    case REPLICATE = 'replicate';
    case SUNO = 'suno';
    case CLOUDFLARE = 'cloudflare';

    public function label(): string
    {
        return match ($this) {
            self::REPLICATE => 'Replicate',
            self::SUNO => 'Suno',
            self::CLOUDFLARE => 'Cloudflare Workers AI',
        };
    }

    /**
     * Whether the engine's API key is set.
     */
    public function available(): bool
    {
        return AiSettings::configured($this->value);
    }

    /**
     * The `.env` keys the engine needs, like "Suno API Key (SUNO_API_KEY)".
     */
    public function missingKeys(): string
    {
        return implode(', ', AiSettings::missing($this->value));
    }
}
