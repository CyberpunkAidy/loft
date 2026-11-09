<?php

namespace App\Http\Middleware;

use App\Models\Studio;
use App\Support\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * studio.role:member|instructor|admin|owner — the signed-in user needs at least that role in this studio.
 * Signed-in people who aren't members yet are sent to the studio's join page, then back.
 */
class RequireStudioRole
{
    public function handle(Request $request, Closure $next, string $role = 'member'): Response
    {
        /** @var Studio $studio */
        $studio = $request->route('studio');
        $user = $request->user();

        if ($user && $user->hasRoleIn($studio, $role)) {
            return $next($request);
        }
        if ($request->is('api/*')) {
            throw $role === 'member'
                ? new ApiException('not_a_member', "You’re not a member of {$studio->name} yet. Join first.", 403)
                : new ApiException('forbidden', 'You don’t have access to this part of '.$studio->name.'.', 403);
        }
        if ($user && $role === 'member') {
            if ($request->isMethod('GET')) {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            return redirect()->route('studio.join', ['studio' => $studio->slug]);
        }

        abort(403, 'You don’t have access to this part of '.$studio->name.'.');
    }
}
