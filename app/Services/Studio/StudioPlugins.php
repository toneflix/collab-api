<?php

namespace App\Services\Studio;

use App\Helpers\Providers;

/**
 * Whether people may add Web Audio Module plugins from other sites to their
 * stems. Admins turn it on in the configuration; it is off by default.
 */
class StudioPlugins
{
    public static function enabled(): bool
    {
        return filter_var(Providers::config('studio_plugins', false), FILTER_VALIDATE_BOOL);
    }
}
