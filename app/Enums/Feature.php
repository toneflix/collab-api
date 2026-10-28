<?php

namespace App\Enums;

/**
 * Everything a subscription plan can grant or limit.
 *
 * Toggles are on or off. Limits are numbers, where 0 means no limit. Credits
 * are an amount given each month, where 0 means none.
 * To gate something new, add a case here, give it defaults, and check it with
 * $user->feature(Feature::X) or Limits::for($user).
 */
enum Feature: string
{
    case DOWNLOADS = 'downloads';
    case BADGE = 'badge';
    case SELL_RELEASES = 'sell_releases';
    case SELL_STEMS = 'sell_stems';
    case RECEIVE_TIPS = 'receive_tips';
    case MAX_PROJECTS = 'max_projects';
    case MAX_SONGS = 'max_songs';
    case MAX_COLLABORATORS = 'max_collaborators';
    case MAX_STEM_SIZE = 'max_stem_size';
    case MAX_STORAGE = 'max_storage';
    case HISTORY_DAYS = 'history_days';
    case AI_TOOLS = 'ai_tools';
    case AI_CREDITS = 'ai_credits';
    case CONTENT_AGENT = 'content_agent';

    public function title(): string
    {
        return match ($this) {
            self::DOWNLOADS => 'Download tracks for offline listening',
            self::BADGE => 'Premium badge on your profile',
            self::SELL_RELEASES => 'Sell releases',
            self::SELL_STEMS => 'Sell stems',
            self::RECEIVE_TIPS => 'Receive tips',
            self::MAX_PROJECTS => 'Projects',
            self::MAX_SONGS => 'Compositions',
            self::MAX_COLLABORATORS => 'Collaborators per project',
            self::MAX_STEM_SIZE => 'Largest stem upload',
            self::MAX_STORAGE => 'Sound storage',
            self::HISTORY_DAYS => 'Version history',
            self::AI_TOOLS => 'AI studio tools',
            self::AI_CREDITS => 'AI credits',
            self::CONTENT_AGENT => 'Content agent',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::DOWNLOADS => 'Download any track you can stream.',
            self::BADGE => 'Show a Premium badge next to your name.',
            self::SELL_RELEASES => 'Put a price on your releases.',
            self::SELL_STEMS => 'Put a price on the stems of your released tracks.',
            self::RECEIVE_TIPS => 'Let fans tip you from your profile.',
            self::MAX_PROJECTS => 'How many projects you can create.',
            self::MAX_SONGS => 'How many compositions you can keep in Composer.',
            self::MAX_COLLABORATORS => 'How many collaborators each of your projects can have.',
            self::MAX_STEM_SIZE => 'The largest stem file you can upload.',
            self::MAX_STORAGE => 'Space for your own sounds, uploaded or imported from Freesound.',
            self::HISTORY_DAYS => 'How long earlier versions of your project tracks are kept.',
            self::AI_TOOLS => 'Generate music, split tracks into stems and get lyric suggestions in Composer with AI.',
            self::AI_CREDITS => 'Credits for AI tools each month. Bought credits come on top and never expire.',
            self::CONTENT_AGENT => 'Describe albums, tracks and touch-ups in plain words and let AI build them in your studio, paid with your AI credits.',
        };
    }

    /**
     * toggle (on or off), limit (a number, 0 means no limit) or credits
     * (an amount each month, 0 means none).
     */
    public function type(): string
    {
        return match ($this) {
            self::MAX_PROJECTS, self::MAX_SONGS, self::MAX_COLLABORATORS, self::MAX_STEM_SIZE, self::MAX_STORAGE, self::HISTORY_DAYS => 'limit',
            self::AI_CREDITS => 'credits',
            default => 'toggle',
        };
    }

    public function unit(): ?string
    {
        return match ($this) {
            self::MAX_STEM_SIZE, self::MAX_STORAGE => 'MB',
            self::HISTORY_DAYS => 'days',
            self::AI_CREDITS => 'credits',
            default => null,
        };
    }

    /**
     * What an account gets when no plan says otherwise.
     */
    public function fallback(): bool|int
    {
        return match ($this) {
            self::DOWNLOADS, self::BADGE, self::AI_TOOLS, self::CONTENT_AGENT => false,
            self::SELL_RELEASES, self::SELL_STEMS, self::RECEIVE_TIPS => true,
            self::MAX_PROJECTS => 10,
            self::MAX_SONGS => 10,
            self::MAX_COLLABORATORS => 5,
            self::MAX_STEM_SIZE => 50,
            self::MAX_STORAGE => 200,
            self::HISTORY_DAYS => 30,
            self::AI_CREDITS => 0,
        };
    }

    /**
     * Turn a value from a form or JSON into the right type for this feature.
     */
    public function cast(mixed $value): bool|int
    {
        return $this->type() === 'toggle'
            ? filter_var($value, FILTER_VALIDATE_BOOLEAN)
            : max(0, (int) $value);
    }

    /**
     * The catalogue, for the admin plan editor and the plans page.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function catalogue(): array
    {
        return array_map(fn(self $f) => [
            'key' => $f->value,
            'title' => $f->title(),
            'description' => $f->description(),
            'type' => $f->type(),
            'unit' => $f->unit(),
            'default' => $f->fallback(),
        ], self::cases());
    }
}
