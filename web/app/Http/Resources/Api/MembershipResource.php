<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;

/** A studio the signed-in person belongs to, with their role there. @mixin \App\Models\Studio */
class MembershipResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'role' => $this->pivot?->role ?? 'member',
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'accent_color' => $this->accent_color,
        ];
    }
}
