<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\PlanResource;
use App\Http\Resources\Api\StudioResource;
use App\Models\Studio;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** A studio's public details and what it sells. No sign-in needed. */
class StudioController extends Controller
{
    public function show(Studio $studio): StudioResource
    {
        return new StudioResource($studio);
    }

    public function plans(Studio $studio): AnonymousResourceCollection
    {
        return PlanResource::collection($studio->plans()->where('active', true)->with('season')->orderBy('sort')->orderBy('name')->get()
            ->each->setRelation('studio', $studio));
    }
}
