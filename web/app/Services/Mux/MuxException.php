<?php

namespace App\Services\Mux;

use RuntimeException;

class MuxException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0)
    {
        parent::__construct($message);
    }
}
