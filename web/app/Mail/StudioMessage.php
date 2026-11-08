<?php

namespace App\Mail;

use App\Models\Studio;
use App\Services\Messages;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Every member email: studio-branded, sent from the platform address with the studio's name,
 * replies going to the studio. All text is plain and escaped by the view. Emails still queued
 * for a studio that has since closed are dropped.
 *
 * Send it through App\Services\Messages, which logs the delivery (deliveryId) and, for marketing
 * email, sets the unsubscribe link.
 */
#[DeleteWhenMissingModels]
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

    /** The message_deliveries row this send is logged under. */
    public ?int $deliveryId = null;

    /** Set for marketing email: the footer link and the List-Unsubscribe headers. */
    public ?string $unsubscribeUrl = null;

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

    public function headers(): Headers
    {
        return new Headers(text: $this->unsubscribeUrl ? [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ] : []);
    }

    /** The queue gave up on it. */
    public function failed(Throwable $e): void
    {
        if ($this->deliveryId) {
            app(Messages::class)->markFailed($this->deliveryId, $e);
        }
    }
}
