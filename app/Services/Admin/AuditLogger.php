<?php

namespace App\Services\Admin;

use App\Models\AdminUser;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Http\Request;

/**
 * Append-only admin audit trail (authentication-and-permissions.md §6.4):
 * actor, action (e.g. `user.suspended`), module, target, before/after with
 * secrets redacted, ip, user agent, correlation id.
 */
class AuditLogger
{
    private const REDACT_KEYS = [
        'password', 'password_hash', 'pin', 'pin_hash', 'otp', 'code', 'token', 'secret', 'two_factor_secret',
        'account_number', 'account_number_encrypted', 'id_number', 'id_number_encrypted', 'api_key', 'refresh_token',
        'invite_token_hash', 'remember_token',
    ];

    public function __construct(private readonly Request $request) {}

    public function log(string $action, string $module, ?EloquentModel $target = null, ?string $label = null, ?array $before = null, ?array $after = null): AuditLog
    {
        return $this->record(
            $action,
            $module,
            $target ? $this->targetType($target) : null,
            $target?->getKey(),
            $label,
            $before === null ? null : self::redact($before),
            $after === null ? null : self::redact($after),
        );
    }

    public function record(string $action, string $module, ?string $targetType, ?string $targetId, ?string $targetLabel, ?array $before, ?array $after): AuditLog
    {
        $admin = $this->request->user();
        $this->request->attributes->set('audit_logged', true);

        return AuditLog::query()->create([
            'admin_user_id' => $admin instanceof AdminUser ? $admin->id : null,
            'action' => $action,
            'module' => $module,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_label' => $targetLabel !== null ? mb_substr($targetLabel, 0, 255) : null,
            'before' => $before,
            'after' => $after,
            'ip' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 512),
            'correlation_id' => $this->request->attributes->get('correlation_id'),
        ]);
    }

    /** @param array<string,mixed> $data */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            $normalised = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', (string) $key));
            if (in_array($normalised, self::REDACT_KEYS, true) || str_ends_with($normalised, '_secret') || str_ends_with($normalised, '_token')) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }

    private function targetType(EloquentModel $model): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', class_basename($model)));
    }
}
