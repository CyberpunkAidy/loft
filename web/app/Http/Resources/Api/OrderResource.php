<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;

/** Something the member bought: plans or products, online or in person. @mixin \App\Models\Order */
class OrderResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'paid_at' => self::utc($this->paid_at),
            'channel' => $this->channel,
            'method_label' => $this->methodLabel(),
            'currency' => $this->currency,
            'subtotal_cents' => $this->subtotal_cents,
            'discount_cents' => $this->discount_cents,
            'tax_cents' => $this->tax_cents,
            'total_cents' => $this->total_cents,
            'total_label' => $this->totalLabel(),
            'refunded_cents' => $this->refunded_cents,
            'refunded_at' => self::utc($this->refunded_at),
            'fulfillment_status' => $this->fulfillment_status,
            'items' => $this->items->map(fn ($i) => ['kind' => $i->kind, 'description' => $i->description, 'quantity' => $i->quantity, 'total_cents' => $i->total_cents])->values(),
        ];
    }
}
