<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\AdminUser;
use App\Services\Admin\SecurityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `permission:<key>` on every /admin/* route. Deny by default: permissions are
 * read fresh from roles on each request (not cached in tokens). Denials return
 * 403 FORBIDDEN and are written to security_logs as `permission_denied`.
 */
class RequireAdminPermission
{
    public function __construct(private readonly SecurityLogger $security) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $admin = $request->user();
        if (! $admin instanceof AdminUser) {
            throw ApiException::unauthenticated();
        }

        if (! $admin->hasPermission($permission)) {
            $this->security->log('permission_denied', $admin, $admin->email, [
                'permission' => $permission,
                'route' => $request->route()?->getName() ?? $request->path(),
                'method' => $request->method(),
            ]);
            throw ApiException::forbidden('You do not have permission to perform this action.');
        }

        $request->attributes->set('admin_permission', $permission);

        return $next($request);
    }
}
