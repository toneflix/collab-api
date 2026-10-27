<?php

namespace App\Services\Storage;

use App\Helpers\Providers;

/**
 * Validation rules for uploads, built from the allowed extensions settings.
 */
class UploadRules
{
    /**
     * A "mimes:" rule from a setting such as allowed_audio_exts.
     */
    public static function mimes(string $setting): string
    {
        $exts = collect(Providers::config($setting))
            ->map(fn($ext) => ltrim(mb_strtolower(trim((string) $ext)), '.'))
            ->filter();

        // Some phones' .m4a recordings are detected as video/mp4, which reads as "mp4"
        if ($exts->contains('m4a')) {
            $exts->push('mp4');
        }

        return 'mimes:' . $exts
            ->unique()
            ->join(',');
    }
}
