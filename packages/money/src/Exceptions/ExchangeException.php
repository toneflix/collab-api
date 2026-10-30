<?php

namespace Toneflix\Money\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A failed exchange rate lookup. `type` is the provider's error type, such as
 * `invalid-key`, `quota-reached` or `unsupported-code`.
 */
class ExchangeException extends RuntimeException
{
    protected const MESSAGES = [
        'missing-key' => 'Account is inactive, API Key is missing or the provided API Key is invalid. Please check and try again.',
        'invalid-key' => 'Account is inactive, API Key is missing or the provided API Key is invalid. Please check and try again.',
        'inactive-account' => 'Account is inactive, API Key is missing or the provided API Key is invalid. Please check and try again.',
        'quota-reached' => 'API request quota reached. Please upgrade your plan or wait until the quota resets.',
        'unsupported-code' => 'The provided currency code is not supported. Please check and try again.',
        'malformed-request' => 'The request was malformed. Please check the parameters and try again.',
        'missing-source' => 'Source currency not specified',
        'missing-target' => 'Target currency not specified',
    ];

    public function __construct(
        public readonly string $type,
        public readonly ?string $provider = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(self::MESSAGES[$type] ?? $type, 0, $previous);
    }
}
