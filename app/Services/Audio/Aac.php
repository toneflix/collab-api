<?php

namespace App\Services\Audio;

use FFMpeg\Format\Audio\Aac as BaseAac;

/**
 * AAC format that also allows FFmpeg's native "aac" encoder,
 * for servers where libfdk_aac is not installed.
 */
class Aac extends BaseAac
{
    public function getAvailableAudioCodecs()
    {
        return ['libfdk_aac', 'aac'];
    }
}
