<?php

namespace App\Services\Composer;

use App\Services\Settings\SettingsStore;

/**
 * Admin settings for Composer, the songwriting notepad. The Datamuse key comes from `.env`.
 */
class ComposerSettings extends SettingsStore
{
    protected const CACHE_KEY = 'composer::settings';

    public const WORD_SOURCES = ['datamuse', 'local'];

    /**
     * Every setting: key => [title, default, hint].
     *
     * @var array<string, array{0: string, 1: string|null, 2?: string}>
     */
    public const FIELDS = [
        'composer_word_source' => ['Word Source', 'datamuse', 'Datamuse answers completion, rhymes, related words and definitions. Local uses only the built-in dictionary, which has no meanings.'],
    ];

    public const KEYS = [
        'datamuse' => [
            'DATAMUSE_API_KEY' => ['Datamuse API Key', 'services.datamuse.key', false],
        ],
    ];

    public static function wordSource(): string
    {
        $source = (string) self::get('composer_word_source', 'datamuse');

        return in_array($source, self::WORD_SOURCES, true) ? $source : 'datamuse';
    }
}
