<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\UserResource;
use Illuminate\Http\Request;

/** The signed-in person: who they are, and the studios they belong to. */
class MeController extends Controller
{
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user()->load('studios'));
    }

    /** Name and phone. Email and password are changed on the website, which can confirm them. */
    public function update(Request $request): UserResource
    {
        $request->user()->update($request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]));

        return new UserResource($request->user()->load('studios'));
    }
}
