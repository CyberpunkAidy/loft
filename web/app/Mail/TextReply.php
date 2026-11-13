<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A member's reply to the studio's texting number, forwarded to its owners and admins (decision 48).
 * Replying to the email answers the member by email, when Loft knows who they are.
 */
class TextReply extends LoftNotice
{
    public ?string $memberEmail = null;

    public ?string $memberName = null;

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), (string) config('app.name')),
            replyTo: $this->memberEmail ? [new Address($this->memberEmail, (string) $this->memberName)] : [],
            subject: $this->headline,
            tags: ['loft', $this->kind],
        );
    }
}
