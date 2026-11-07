<?php

namespace App\Http\Controllers;

use App\Services\Stripe\StripeWebhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /stripe/webhook — the Connect webhook endpoint for every studio's account. */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeWebhooks $webhooks): JsonResponse
    {
        [$status, $body] = $webhooks->receive($request->getContent(), (string) $request->header('Stripe-Signature', ''));

        return response()->json($body, $status);
    }
}
