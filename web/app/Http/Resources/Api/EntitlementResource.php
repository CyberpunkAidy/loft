<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;

/** A membership or a bundle of class credits the member holds. @mixin \App\Models\Entitlement */
class EntitlementResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'plan' => $this->plan ? ['id' => $this->plan->id, 'name' => $this->plan->name, 'kind' => $this->plan->kind] : null,
            'credits_total' => $this->credits_total,
            'credits_remaining' => $this->credits_remaining,
            'weekly_limit' => $this->weekly_limit,
            'expires_at' => self::utc($this->expires_at),
            'current_period_end' => self::utc($this->current_period_end),
        ];
    }
}
