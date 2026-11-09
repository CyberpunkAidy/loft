<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;

/** The signed-in person, and the studios they belong to. @mixin \App\Models\User */
class UserResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'studios' => MembershipResource::collection($this->whenLoaded('studios')),
        ];
    }
}
