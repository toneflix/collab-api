<?php

namespace App\Services\Composer\Words;

use RuntimeException;

/**
 * A word source could not answer, so the local dictionary answers instead.
 */
class WordSourceUnavailable extends RuntimeException {}
