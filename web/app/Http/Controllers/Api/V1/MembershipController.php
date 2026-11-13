<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\MembershipResource;
use App\Models\Studio;
use App\Services\Memberships;
use App\Services\Texts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Joining a studio from the app: a new account (signed in straight away), or an existing one. */
class MembershipController extends Controller
{
    public function register(Request $request, Studio $studio, Memberships $memberships): JsonResponse
    {
        $data = $request->validate(Memberships::registrationRules() + [
            'device_name' => ['required', 'string', 'max:100'],
            'marketing_email' => ['nullable', 'boolean'],
            'text_updates' => ['nullable', 'boolean'],
        ], ['email.unique' => 'An account with that email already exists. Sign in, then join.']);
        $texts = $studio->textsReady() && $request->boolean('text_updates');
        if ($texts) {
            Texts::assertTextable($data['phone'] ?? null);
        }
        $user = $memberships->register($studio, $data, $request->boolean('marketing_email'), $texts);

        return TokenController::issued($user, $data['device_name']);
    }

    public function join(Request $request, Studio $studio, Memberships $memberships): JsonResponse
    {
        $request->validate(['marketing_email' => ['nullable', 'boolean']]);
        $joined = $memberships->join($studio, $request->user(), $request->boolean('marketing_email'));
        $studio = $request->user()->studios()->whereKey($studio->id)->sole();

        return (new MembershipResource($studio))->response()->setStatusCode($joined ? 201 : 200);
    }
}
