<?php

namespace App\Http\Middleware;

use App\Models\Studio;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Switches an action off in a demo studio (decision 44): anything that would take a real payment,
 * open a Stripe account, upload to Mux or connect Twilio. The visitor is sent back with a note saying what it does
 * in a real studio. Use as `demo.off:payments`, `demo.off:subscription`, `demo.off:video`, `demo.off:texts`.
 */
class NotInDemo
{
    private const NOTES = [
        'payments' => 'Payments are switched off in the demo, so nothing here charges a card or refunds one. In your own studio, you connect Stripe and members pay you directly.',
        'subscription' => 'There’s nothing to pay for in the demo. In your own studio, this is where you subscribe to Loft.',
        'video' => 'Uploading videos is switched off in the demo. In your own studio, you upload classes to your on-demand library here.',
        'texts' => 'Texts are switched off in the demo. In your own studio, you connect your Twilio number here, and members who opt in get reminders and updates by text.',
    ];

    public function handle(Request $request, Closure $next, string $what = 'payments'): Response
    {
        $studio = $request->route('studio');
        if ($studio instanceof Studio && $studio->isDemo()) {
            return back()->with('warning', self::NOTES[$what] ?? self::NOTES['payments']);
        }

        return $next($request);
    }
}
