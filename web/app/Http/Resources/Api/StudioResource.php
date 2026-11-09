<?php

namespace App\Http\Resources\Api;

use App\Models\Studio;
use Illuminate\Http\Request;

/** @mixin Studio */
class StudioResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'accent_color' => $this->accent_color,
            'contact_email' => $this->contact_email,
            'website_url' => route('studio.schedule', ['studio' => $this->slug]),
            'online_payments' => $this->stripeReady(),
            'policies' => [
                'late_cancel_hours' => (int) $this->setting('late_cancel_hours'),
                'booking_opens_days_before' => (int) $this->setting('booking_opens_days_before'),
                'booking_closes_minutes_before' => (int) $this->setting('booking_closes_minutes_before'),
                'minor_age' => (int) $this->setting('minor_age'),
            ],
        ];
    }
}
