<?php

namespace App\Support;

use RuntimeException;

/**
 * A sale that can't be refunded from Loft ($refunded false), or a refund that went through while
 * ending the membership didn't ($refunded true).
 */
class RefundException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $refunded = false)
    {
        parent::__construct($message);
    }
}
