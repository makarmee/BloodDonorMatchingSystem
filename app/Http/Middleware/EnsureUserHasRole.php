<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Ensure the authenticated user is active and holds at least one required role.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_active) {
            abort(403);
        }

        if ($roles !== [] && ! $user->hasAnyRole($roles)) {
            abort(403);
        }

        return $next($request);
    }
}
