<?php

namespace App\Enums;

/**
 * What someone mainly does on Toneflix Music, picked at sign-up, with the
 * identities that fit it.
 */
enum AccountType: string
{
    case CREATOR = 'creator';
    case LISTENER = 'listener';

    /**
     * How many identities one account can pick.
     */
    public const MAX_IDENTITIES = 6;

    public function label(): string
    {
        return match ($this) {
            self::CREATOR => 'Creator',
            self::LISTENER => 'Listener',
        };
    }

    /**
     * The identities this account type can pick, as value => label.
     *
     * @return array<string, string>
     */
    public function identities(): array
    {
        return match ($this) {
            self::CREATOR => [
                'artist' => 'Artist',
                'producer' => 'Producer',
                'songwriter' => 'Songwriter',
                'dj' => 'DJ',
                'engineer' => 'Mix or mastering engineer',
                'instrumentalist' => 'Instrumentalist',
                'vocalist' => 'Vocalist',
            ],
            self::LISTENER => [
                'fan' => 'Music fan',
                'curator' => 'Playlist curator',
                'content_creator' => 'Content creator',
                'dancer' => 'Dancer',
                'student' => 'Music student',
                'industry' => 'Label, A&R or media',
            ],
        };
    }

    /**
     * Every account type with its identities, for sign-up and settings forms.
     *
     * @return list<array{value: string, label: string, identities: list<array{value: string, label: string}>}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn(self $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'identities' => collect($type->identities())
                    ->map(fn(string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values()
                    ->all(),
            ])
            ->all();
    }
}
