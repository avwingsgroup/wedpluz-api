<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

class AdminRole extends Model
{
    protected $table = 'admin_roles';

    protected static string $idPrefix = 'rol';

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function admins(): BelongsToMany
    {
        return $this->belongsToMany(AdminUser::class, 'admin_user_roles', 'role_id', 'admin_user_id');
    }

    public function permissionKeys(): array
    {
        return DB::table('admin_role_permissions')->where('role_id', $this->id)->orderBy('permission_key')->pluck('permission_key')->all();
    }

    public function syncPermissions(array $keys): void
    {
        DB::table('admin_role_permissions')->where('role_id', $this->id)->delete();
        DB::table('admin_role_permissions')->insert(array_map(fn ($k) => ['role_id' => $this->id, 'permission_key' => $k], array_values(array_unique($keys))));
    }
}
