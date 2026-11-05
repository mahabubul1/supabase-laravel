<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class VerifySupabaseToken
{
    public function handle(Request $request, Closure $next, string $guard = 'supabase'): Response
    {
        if (Auth::guard($guard)->guest()) {
            abort(401, 'Unauthenticated.');
        }

        return $next($request);
    }
}
