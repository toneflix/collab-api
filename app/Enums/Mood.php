<?php

namespace App\Enums;

/**
 * The moods a song can be tagged with. Each has the words the style tagger
 * listens for in the audio.
 */
enum Mood: string
{
    case HAPPY = 'happy';
    case SAD = 'sad';
    case ENERGETIC = 'energetic';
    case CALM = 'calm';
    case ROMANTIC = 'romantic';
    case DARK = 'dark';
    case UPLIFTING = 'uplifting';
    case AGGRESSIVE = 'aggressive';
    case DREAMY = 'dreamy';
    case MELANCHOLIC = 'melancholic';
    case PLAYFUL = 'playful';
    case EPIC = 'epic';
    case TENSE = 'tense';
    case NOSTALGIC = 'nostalgic';
    case GROOVY = 'groovy';
    case SENSUAL = 'sensual';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * How the style tagger describes the mood.
     */
    public function prompt(): string
    {
        return match ($this) {
            self::HAPPY => 'This song sounds happy and cheerful.',
            self::SAD => 'This song sounds sad and sorrowful.',
            self::ENERGETIC => 'This song sounds energetic and driving.',
            self::CALM => 'This song sounds calm and relaxing.',
            self::ROMANTIC => 'This song sounds romantic and loving.',
            self::DARK => 'This song sounds dark and ominous.',
            self::UPLIFTING => 'This song sounds uplifting and inspiring.',
            self::AGGRESSIVE => 'This song sounds aggressive and angry.',
            self::DREAMY => 'This song sounds dreamy and atmospheric.',
            self::MELANCHOLIC => 'This song sounds melancholic and wistful.',
            self::PLAYFUL => 'This song sounds playful and fun.',
            self::EPIC => 'This song sounds epic and powerful.',
            self::TENSE => 'This song sounds tense and suspenseful.',
            self::NOSTALGIC => 'This song sounds nostalgic and bittersweet.',
            self::GROOVY => 'This song sounds groovy and danceable.',
            self::SENSUAL => 'This song sounds sensual and seductive.',
        };
    }

    /**
     * The moods named in a comma list, skipping unknown ones.
     *
     * @return list<self>
     */
    public static function parse(?string $list): array
    {
        return collect(explode(',', (string) $list))
            ->map(fn(string $mood) => self::tryFrom(mb_strtolower(trim($mood))))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
