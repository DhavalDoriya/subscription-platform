<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Throw for expected, user-facing failures (business rules). The renderer turns it into
 * { message, code, errors? } with the given status. `code` is a stable machine-readable string.
 */
class ApiException extends RuntimeException
{
    /** @param array<string, array<int, string>>|null $errors */
    public function __construct(
        string $message,
        public readonly int $status = 400,
        public readonly ?string $errorCode = null,
        public readonly ?array $errors = null,
    ) {
        parent::__construct($message);
    }
}
