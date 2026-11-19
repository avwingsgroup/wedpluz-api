<?php

namespace App\Services\Admin;

use App\Models\AdminUser;
use App\Models\SecurityLog;
use Illuminate\Http\Request;

/**
 * Append-only security log for admin authentication events: login_success,
 * login_failed, logout, password_changed, 2fa_enabled, 2fa_disabled,
 * permission_denied, session_revoked.
 */
class SecurityLogger
{
    public function __construct(private readonly Request $request) {}

    public function log(string $event, ?AdminUser $admin, ?string $email = null, array $context = []): SecurityLog
    {
        return SecurityLog::query()->create([
            'admin_user_id' => $admin?->id,
            'email_attempted' => $email !== null ? mb_strtolower(mb_substr($email, 0, 255)) : null,
            'event' => $event,
            'ip' => $this->request->ip(),
            'location' => null,
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 512),
            'context' => $context + ['correlationId' => $this->request->attributes->get('correlation_id')],
        ]);
    }
}
