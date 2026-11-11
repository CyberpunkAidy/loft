<?php

namespace App\Http\Middleware;

use App\Support\SignupSource;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Notes where a guest's visit began, for the studio they might sign up (decision 43). */
class RememberSignupSource
{
    public function handle(Request $request, Closure $next): Response
    {
        SignupSource::remember($request);

        return $next($request);
    }
}
