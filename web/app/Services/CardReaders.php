<?php

namespace App\Services;

use App\Models\CardReader;
use App\Models\Studio;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeException;

/**
 * The studio's Stripe Terminal card readers (Admin → Card readers). They live on the studio's own
 * Stripe account, at one Terminal Location made from the studio's address; Loft keeps a copy of each
 * (its label and model) so the till can offer them without asking Stripe.
 */
class CardReaders
{
    public function __construct(private readonly StripeClient $stripe) {}

    /** @param  array{line1: string, line2?: ?string, city: string, state?: ?string, postal_code: string, country: string}  $address */
    public function setLocation(Studio $studio, array $address): void
    {
        $this->stripe->createTerminalLocation($studio, array_filter($address, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Pair a reader with the code it shows (Settings → Generate pairing code, on the reader).
     *
     * @throws StripeException
     */
    public function register(Studio $studio, string $code, string $label): CardReader
    {
        return $this->remember($studio, $this->stripe->registerReader($studio, $code, $label));
    }

    /**
     * Stripe's current list, with each reader's status (online or offline). Readers added or removed
     * in the Stripe Dashboard are picked up or dropped here.
     *
     * @return array<string, array<string, mixed>> Stripe reader objects by ID
     *
     * @throws StripeException
     */
    public function sync(Studio $studio): array
    {
        $live = collect($this->stripe->listReaders($studio))->keyBy('id');
        foreach ($live as $reader) {
            $this->remember($studio, $reader);
        }
        $studio->cardReaders()->whereNotIn('stripe_reader_id', $live->keys()->all())->delete();

        return $live->all();
    }

    /** @throws StripeException */
    public function remove(CardReader $reader): void
    {
        try {
            $this->stripe->deleteReader($reader->studio, $reader->stripe_reader_id);
        } catch (StripeException $e) {
            if ($e->stripeCode !== 'resource_missing') {
                throw $e; // already gone from Stripe: just forget it here
            }
        }
        $reader->delete();
    }

    private function remember(Studio $studio, array $reader): CardReader
    {
        return CardReader::updateOrCreate(['stripe_reader_id' => $reader['id']], [
            'studio_id' => $studio->id,
            'label' => mb_substr((string) ($reader['label'] ?? $reader['serial_number'] ?? 'Card reader'), 0, 80),
            'device_type' => $reader['device_type'] ?? null,
            'serial_number' => $reader['serial_number'] ?? null,
        ]);
    }
}
