<?php

namespace App\Models;

use App\Support\Concerns\HasPrefixedId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

/**
 * Wedpluz staff. Separate table, guard and token audience from customers.
 * Permissions are resolved per request from roles (never baked into tokens).
 */
class AdminUser extends Authenticatable
{
    use HasApiTokens, HasFactory, HasPrefixedId;

    protected static string $idPrefix = 'adm';

    protected $table = 'admin_users';

    protected $guarded = [];

    protected $hidden = ['password', 'two_factor_secret', 'invite_token_hash'];

    /** @var list<string>|null */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_enabled' => 'boolean',
            'two_factor_confirmed_at' => 'immutable_datetime',
            'invite_expires_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
        ];
    }

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:sP';
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(AdminRole::class, 'admin_user_roles', 'admin_user_id', 'role_id');
    }

    /** @return list<string> */
    public function permissionKeys(): array
    {
        return $this->permissionCache ??= DB::table('admin_user_roles')
            ->join('admin_role_permissions', 'admin_role_permissions.role_id', '=', 'admin_user_roles.role_id')
            ->where('admin_user_roles.admin_user_id', $this->id)
            ->distinct()
            ->orderBy('permission_key')
            ->pluck('permission_key')
            ->all();
    }

    public function hasPermission(string $key): bool
    {
        return in_array($key, $this->permissionKeys(), true);
    }

    public function flushPermissionCache(): void
    {
        $this->permissionCache = null;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
