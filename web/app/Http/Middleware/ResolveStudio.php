<?php

namespace App\Http\Middleware;

use App\Models\Studio;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the studio in the URL the current studio: route() calls fill in {studio}, and every view gets $studio.
 * The {studio} binding itself is registered in AppServiceProvider so child bindings are always scoped to it.
 */
class ResolveStudio
{
    public function handle(Request $request, Closure $next): Response
    {
        $studio = $request->route('studio');
        abort_unless($studio instanceof Studio, 404);

        URL::defaults(['studio' => $studio->slug]);
        View::share('studio', $studio);

        return $next($request);
    }
}
