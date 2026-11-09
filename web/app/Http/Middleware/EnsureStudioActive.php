<?php

namespace App\Http\Middleware;

use App\Models\Studio;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * studio.active — on the staff side: while the studio is paused for want of a Loft subscription
 * (decision 36), every admin page leads to the subscription page, the one page left open.
 */
class EnsureStudioActive
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Studio $studio */
        $studio = $request->route('studio');

        if ($studio->isPaused() && ! $request->routeIs('studio.admin.subscription*')) {
            return redirect()->route('studio.admin.subscription');
        }

        return $next($request);
    }
}
