<?php

namespace App\Services\Twilio;

use RuntimeException;

/** An error from Twilio's API: its error code (21211, 21610…), and the HTTP status (0 if unreachable). */
class TwilioException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $twilioCode = null, public readonly int $httpStatus = 0)
    {
        parent::__construct($message);
    }

    /** Worth trying again later: Twilio was unreachable, busy or failing on its side. */
    public function isTemporary(): bool
    {
        return $this->httpStatus === 0 || $this->httpStatus === 429 || $this->httpStatus >= 500;
    }
}
