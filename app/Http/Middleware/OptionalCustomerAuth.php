<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Personalised public endpoints (api-contracts.md §1.7): the token is optional,
 * but when one is sent it must be a valid customer token. An expired or invalid
 * token returns 401 so the client refreshes; it never silently downgrades to
 * the guest view.
 */
class OptionalCustomerAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() === null) {
            return $next($request);
        }

        $user = Auth::guard('sanctum')->user();
        if (! $user instanceof User || ! $user->currentAccessToken()?->can('customer')) {
            throw ApiException::unauthenticated();
        }
        if ($user->isSuspended()) {
            throw ApiException::accountSuspended();
        }

        Auth::shouldUse('sanctum');
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
