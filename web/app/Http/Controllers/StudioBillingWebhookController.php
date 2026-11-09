<?php

namespace App\Http\Controllers;

use App\Services\Stripe\StudioBillingWebhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /stripe/billing-webhook — events on Loft's own Stripe account about studios' Loft subscriptions. */
class StudioBillingWebhookController extends Controller
{
    public function __invoke(Request $request, StudioBillingWebhooks $webhooks): JsonResponse
    {
        [$status, $body] = $webhooks->receive($request->getContent(), (string) $request->header('Stripe-Signature', ''));

        return response()->json($body, $status);
    }
}
