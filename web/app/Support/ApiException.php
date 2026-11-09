<?php

namespace App\Support;

use RuntimeException;

/** An API error with a stable code the app can act on (rendered by App\Http\ApiErrors). */
class ApiException extends RuntimeException
{
    /** @param  array<string, mixed>  $details  extra fields in the error object */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
