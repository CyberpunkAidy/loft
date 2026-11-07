<?php

namespace App\Mail;

use App\Models\Studio;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Every member email: studio-branded, sent from the platform address with the studio's name,
 * replies going to the studio. All text is plain and escaped by the view.
 */
class StudioMessage extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $lines  paragraphs before the details
     * @param  array<string, string>  $details  label => value rows (class, time, attendee…)
     * @param  list<string>  $after  paragraphs after the details
     */
    public function __construct(
        public Studio $studio,
        public string $headline,
        public array $lines = [],
        public array $details = [],
        public array $after = [],
        public ?string $actionUrl = null,
        public ?string $actionLabel = null,
        public string $kind = 'message',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->studio->email_from_name ?: $this->studio->name),
            replyTo: $this->studio->contact_email ? [new Address($this->studio->contact_email, $this->studio->name)] : [],
            subject: '['.$this->studio->name.'] '.$this->headline,
            tags: ['loft', $this->kind],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.studio-message', text: 'mail.studio-message-text');
    }
}
