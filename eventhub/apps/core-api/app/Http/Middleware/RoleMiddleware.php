<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role middleware — checks that the authenticated user has one of the allowed roles.
 *
 * Usage in routes: ->middleware('role:vendor,admin')
 */
class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return ApiResponse::unauthorized();
        }

        if (!in_array($user->role->value, $roles, true)) {
            return ApiResponse::forbidden(
                "This action requires one of the following roles: " . implode(', ', $roles)
            );
        }

        return $next($request);
    }
}
