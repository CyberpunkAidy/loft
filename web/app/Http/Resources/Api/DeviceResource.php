<?php

namespace App\Http\Resources\Api;

use App\Models\UserDevice;
use Illuminate\Http\Request;

/** @mixin UserDevice */
class DeviceResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'name' => $this->name,
            'app_version' => $this->app_version,
            'last_seen_at' => self::utc($this->last_seen_at),
        ];
    }
}
