<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;

/** A membership, class pack, drop-in or season the studio sells. @mixin \App\Models\Plan */
class PlanResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $season = $this->kind === 'season' ? $this->season : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'kind_label' => $this->kindLabel(),
            'description' => $this->description,
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,
            'price_label' => $this->priceLabel(),
            'detail_label' => $this->detailLabel(),
            'billing_interval' => $this->billing_interval,
            'credits' => $this->credits,
            'credit_validity_days' => $this->credit_validity_days,
            'weekly_limit' => $this->weekly_limit,
            'registration_fee_cents' => $this->registration_fee_cents,
            'includes_videos' => $this->includes_videos,
            'season' => $season ? ['id' => $season->id, 'name' => $season->name, 'starts_on' => $season->starts_on->toDateString(), 'ends_on' => $season->ends_on->toDateString()] : null,
            'can_buy_online' => $this->studio->stripeReady() && $this->isStripeReady() && ! ($season && $season->ends_on->copy()->endOfDay()->isPast()),
        ];
    }
}
