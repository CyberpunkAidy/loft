<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\UserResource;
use App\Models\User;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

/** Signing in on the app (one token per install) and signing out. */
class TokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);
        $user = User::where('email', strtolower($data['email']))->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw new ApiException('invalid_credentials', 'That email and password don’t match an account.', 422);
        }

        return self::issued($user, $data['device_name']);
    }

    /** Sign out this install: its token stops working, and its push token (if given) is dropped. */
    public function destroy(Request $request): Response
    {
        $user = $request->user();
        if ($push = $request->input('push_token')) {
            $user->devices()->where('token', (string) $push)->delete();
        }
        $user->currentAccessToken()->delete();

        return response()->noContent();
    }

    /** A new token for $user, with who they are. */
    public static function issued(User $user, string $deviceName): JsonResponse
    {
        $token = $user->createToken($deviceName, ['member']);

        return response()->json(['data' => [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => (new UserResource($user->load('studios')))->resolve(),
        ]], 201);
    }
}
