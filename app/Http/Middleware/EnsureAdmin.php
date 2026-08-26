<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!$user->isActive()) {
            return response()->json([
                'message' => 'Your account has been suspended. Please contact system support.',
            ], 403);
        }

        if (!$user->isStaffOrAdmin()) {
            return response()->json([
                'message' => 'Unauthorized access. Administrator privileges required.',
            ], 403);
        }

        return $next($request);
    }
}
