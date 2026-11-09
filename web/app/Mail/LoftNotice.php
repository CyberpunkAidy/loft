<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An email from Loft itself to a studio's owners (their Loft subscription), rather than from the
 * studio to its members: Loft-branded, from Loft's name. A StudioMessage underneath, so it's sent
 * and logged through App\Services\Messages like every other email, under the studio it's about.
 */
class LoftNotice extends StudioMessage
{
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), (string) config('app.name')),
            subject: $this->headline,
            tags: ['loft', $this->kind],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.loft-notice', text: 'mail.loft-notice-text');
    }
}
