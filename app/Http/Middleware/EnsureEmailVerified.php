<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Email verification gate: unverified users may browse, save and message, but
 * buying tickets, creating events and using the wallet return 403.
 */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && ! $user->hasVerifiedEmail()) {
            throw ApiException::forbidden('Verify your email to continue.');
        }

        return $next($request);
    }
}
