<?php

namespace App\Services\Studio;

use App\Models\ProjectTrackStem;
use RuntimeException;

/**
 * Audio for instrument stems, which are saved as notes with no audio file.
 * When something on the server needs one's audio (AI tools, transcription,
 * downloads), the studio renders the stem alone in headless Chrome. The file
 * is kept until the stem's instrument or effects change.
 */
class InstrumentAudio
{
    public function __construct(
        protected StemFiles $files,
        protected BrowserMix $browser,
    ) {}

    /**
     * Whether a stem is played from notes and has no audio file of its own.
     */
    public static function isInstrument(ProjectTrackStem $stem): bool
    {
        return ! $stem->src && ! empty($stem->meta['instrument']);
    }

    /**
     * What the stem's audio depends on: its instrument, notes and effects.
     */
    public static function hash(ProjectTrackStem $stem): string
    {
        return sha1(json_encode([$stem->meta['instrument'] ?? null, $stem->meta['effects'] ?? null]));
    }

    /**
     * The stem's rendered audio file on the stems disk, if it is up to date.
     */
    public function current(ProjectTrackStem $stem): ?string
    {
        $bounce = $stem->meta['bounce'] ?? null;

        return is_array($bounce) && ($bounce['hash'] ?? null) === self::hash($stem) && ! empty($bounce['file'])
            ? (string) $bounce['file']
            : null;
    }

    /**
     * The name of a stem's audio file on the stems disk: its own file, or for
     * an instrument stem a rendering of it, made now when there's none yet.
     */
    public function file(ProjectTrackStem $stem): ?string
    {
        if (! self::isInstrument($stem)) {
            return $stem->src;
        }

        if ($file = $this->current($stem)) {
            return $file;
        }

        $hash = self::hash($stem);

        if ($this->browser->renderStem($stem, $hash) !== BrowserMix::DONE) {
            throw new RuntimeException('The instrument stem has no notes to play.');
        }

        return $this->current($stem->refresh())
            ?? throw new RuntimeException('The instrument stem\'s audio was not saved.');
    }

    /**
     * Keep audio the studio rendered for a stem, unless the stem changed since.
     * Returns whether it was kept.
     */
    public function keep(ProjectTrackStem $stem, string $path, string $hash): bool
    {
        if (! self::isInstrument($stem) || $hash !== self::hash($stem)) {
            return false;
        }

        $old = $stem->meta['bounce']['file'] ?? null;
        $name = $this->files->put($path, 'flac');

        $stem->meta = [
            ...collect($stem->meta)
                ->except('src')
                ->all(),
            'bounce' => ['file' => $name, 'hash' => $hash],
        ];
        $stem->saveQuietly();
        $this->files->delete(is_string($old) ? $old : null);

        return true;
    }
}
