<?php

namespace App\Support;

use RuntimeException;

/**
 * A booking rule said no. $reason is machine-readable (the plugin's WP_Error codes):
 * not_found, forbidden, cancelled, closed, duplicate, waiver, age, needs_plan, invalid.
 */
class BookingException extends RuntimeException
{
    /** @param array<string, mixed> $context e.g. ['plans' => Collection] for needs_plan */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
