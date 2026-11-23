<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // A demo account is never admin-reachable: a stray promotion cannot
        // turn an unauthenticated-entry session into an admin one.
        abort_unless($request->user() instanceof User && $request->user()->is_admin && ! $request->user()->is_demo, 403);

        return $next($request);
    }
}
