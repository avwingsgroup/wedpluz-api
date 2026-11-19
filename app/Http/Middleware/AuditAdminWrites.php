<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use App\Services\Admin\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarantees that every successful non-GET admin call writes an audit entry.
 * Services record rich entries (action, target, before/after) via AuditLogger;
 * when a handler did not, this writes a generic entry from the route so no
 * admin write goes unlogged.
 */
class AuditAdminWrites
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethod('GET') || $response->getStatusCode() >= 400) {
            return $response;
        }
        if ($request->attributes->get('audit_logged')) {
            return $response;
        }
        $admin = $request->user();
        if (! $admin instanceof AdminUser) {
            return $response;
        }

        $routeName = $request->route()?->getName() ?? strtolower($request->method()).' '.$request->path();
        $module = explode('.', (string) $request->attributes->get('admin_permission', 'admin'))[0];
        $params = $request->route()?->parameters() ?? [];
        $targetId = is_string(reset($params)) ? reset($params) : null;

        $this->audit->record(
            action: str_replace('admin.', '', $routeName),
            module: $module,
            targetType: null,
            targetId: $targetId,
            targetLabel: null,
            before: null,
            after: AuditLogger::redact($request->except(['password', 'otp', 'pin'])),
        );

        return $response;
    }
}
