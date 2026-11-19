<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\AdminUser;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the token audience after `auth:sanctum`.
 *
 *   audience:customer  -> token must belong to a User with the `customer` ability
 *   audience:admin     -> token must belong to an AdminUser with the `admin` ability
 *
 * A customer token on /admin/* (or vice versa) is rejected with 401, as
 * required by authentication-and-permissions.md §1. Suspended accounts get
 * 403 ACCOUNT_SUSPENDED; idle admin sessions expire after the idle timeout.
 */
class EnsureAudience
{
    public function handle(Request $request, Closure $next, string $audience): Response
    {
        $user = $request->user();
        /** @var PersonalAccessToken|null $token */
        $token = $user?->currentAccessToken();

        if ($audience === 'customer') {
            if (! $user instanceof User || ! $token instanceof PersonalAccessToken || ! $token->can('customer')) {
                throw ApiException::unauthenticated();
            }
            if ($user->isSuspended()) {
                $token->delete();
                throw ApiException::accountSuspended();
            }
            $this->touchLastSeen($user);
        } elseif ($audience === 'admin') {
            if (! $user instanceof AdminUser || ! $token instanceof PersonalAccessToken || ! $token->can('admin')) {
                throw ApiException::unauthenticated('Your admin session has expired. Please sign in again.');
            }
            if (! $user->isActive()) {
                $token->delete();
                throw ApiException::accountSuspended('This administrator account is not active.');
            }
            // The 30-minute idle timeout is enforced in Sanctum's token
            // validation callback (AppServiceProvider), before last_used_at is bumped.
        } else {
            throw ApiException::unauthenticated();
        }

        return $next($request);
    }

    private function touchLastSeen(User $user): void
    {
        if ($user->last_seen_at === null || $user->last_seen_at->lt(now()->subMinutes(5))) {
            User::query()->whereKey($user->id)->update(['last_seen_at' => now()]);
        }
    }
}
