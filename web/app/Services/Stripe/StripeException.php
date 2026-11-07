<?php

namespace App\Services\Stripe;

use RuntimeException;

class StripeException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $stripeCode = null, public readonly int $httpStatus = 0)
    {
        parent::__construct($message);
    }
}
