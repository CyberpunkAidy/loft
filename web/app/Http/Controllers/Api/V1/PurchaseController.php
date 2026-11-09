<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\EntitlementResource;
use App\Http\Resources\Api\OrderResource;
use App\Models\Studio;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** What the member has (memberships, credits) and what they've bought (orders). */
class PurchaseController extends Controller
{
    public function entitlements(Request $request, Studio $studio): AnonymousResourceCollection
    {
        return EntitlementResource::collection($studio->entitlements()->with('plan')->where('user_id', $request->user()->id)
            ->orderByRaw("case when status in ('active', 'past_due') then 0 else 1 end")->latest('id')->limit(100)->get());
    }

    public function orders(Request $request, Studio $studio): AnonymousResourceCollection
    {
        $perPage = (int) ($request->validate(['per_page' => ['nullable', 'integer', 'between:1,100']])['per_page'] ?? 25);

        return OrderResource::collection($studio->orders()->sales()->with('items')->where('user_id', $request->user()->id)
            ->orderByDesc('id')->cursorPaginate($perPage)->withQueryString());
    }
}
