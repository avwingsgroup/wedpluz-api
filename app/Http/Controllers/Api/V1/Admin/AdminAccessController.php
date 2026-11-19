<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AdminPermission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\PersonalAccessToken;
use App\Models\SecurityLog;
use App\Services\Admin\AdminPresenter;
use App\Services\Admin\AuditLogger;
use App\Services\Notifications\NotificationService;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Roles, administrators, audit and security logs (§6). An admin cannot grant
 * permissions they do not hold, and `roles.manage` can never be removed from
 * the last active holder. System roles cannot be deleted.
 */
class AdminAccessController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function permissions(): JsonResponse
    {
        return $this->ok(AdminPresenter::permissions());
    }

    public function roles(): JsonResponse
    {
        return $this->ok(AdminRole::query()->orderByDesc('is_system')->orderBy('name')->get()->map(fn ($r) => AdminPresenter::role($r))->all());
    }

    public function createRole(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['present', 'array'], 'permissions.*' => [AdminPermission::rule()]]);
        $this->assertGrantable($data['permissions']);
        $slug = Str::slug($data['name']);
        if (AdminRole::query()->where('slug', $slug)->exists()) {
            throw ApiException::validation('A role with this name exists.', ['name' => ['This role name is taken.']]);
        }
        $role = DB::transaction(function () use ($data, $slug) {
            $role = AdminRole::query()->create(['name' => $data['name'], 'slug' => $slug, 'description' => $data['description'] ?? '', 'is_system' => false]);
            $role->syncPermissions($data['permissions']);

            return $role;
        });
        $this->audit->log('role.created', 'access', $role, $role->name, null, ['permissions' => $data['permissions']]);

        return $this->created(AdminPresenter::role($role));
    }

    public function updateRole(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:100'], 'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'permissions' => ['sometimes', 'array'], 'permissions.*' => [AdminPermission::rule()]]);
        $role = AdminRole::query()->findOrFail($id);
        $before = ['name' => $role->name, 'permissions' => $role->permissionKeys()];
        if ($role->slug === 'super-admin' && isset($data['permissions'])) {
            throw ApiException::conflict('The Super Admin role always has every permission.');
        }

        DB::transaction(function () use ($role, $data) {
            if (isset($data['permissions'])) {
                $added = array_diff($data['permissions'], $role->permissionKeys());
                $this->assertGrantable($added);
                $role->syncPermissions($data['permissions']);
                $this->assertRolesManagerRemains();
            }
            $role->forceFill(array_filter(['name' => $data['name'] ?? null], fn ($v) => $v !== null) + (array_key_exists('description', $data) ? ['description' => (string) $data['description']] : []))->save();
        });
        $this->audit->log('role.updated', 'access', $role, $role->name, $before, ['name' => $role->name, 'permissions' => $role->permissionKeys()]);

        return $this->ok(AdminPresenter::role($role->refresh()));
    }

    public function deleteRole(string $id): Response
    {
        $role = AdminRole::query()->findOrFail($id);
        if ($role->is_system) {
            throw ApiException::conflict('System roles cannot be deleted.');
        }
        DB::transaction(function () use ($role) {
            $role->delete();
            $this->assertRolesManagerRemains();
        });
        $this->audit->log('role.deleted', 'access', $role, $role->name);

        return $this->noContent();
    }

    public function admins(): JsonResponse
    {
        return $this->ok(AdminUser::query()->with('roles')->orderBy('first_name')->get()->map(fn ($a) => AdminPresenter::admin($a))->all());
    }

    public function invite(Request $request, NotificationService $notifications): JsonResponse
    {
        $data = $request->validate(['firstName' => ['required', 'string', 'max:100'], 'lastName' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'], 'roleIds' => ['required', 'array', 'min:1'], 'roleIds.*' => ['string', 'exists:admin_roles,id']]);
        if (AdminUser::query()->whereRaw('lower(email) = ?', [mb_strtolower($data['email'])])->exists()) {
            throw ApiException::conflict('An administrator with this email already exists.');
        }
        $this->assertGrantable(DB::table('admin_role_permissions')->whereIn('role_id', $data['roleIds'])->pluck('permission_key')->all());

        $token = Str::random(48);
        $hours = (int) config('wedpluz.auth.admin_invite_ttl_hours', 72);
        $admin = DB::transaction(function () use ($data, $token, $hours) {
            $admin = AdminUser::query()->create([
                'first_name' => $data['firstName'], 'last_name' => $data['lastName'], 'email' => mb_strtolower($data['email']),
                'status' => 'invited', 'invite_token_hash' => hash('sha256', $token), 'invite_expires_at' => now()->addHours($hours),
                'invited_by_admin_id' => $this->admin()->id,
            ]);
            $admin->roles()->sync($data['roleIds']);

            return $admin;
        });
        $notifications->sendDirect('email', $admin->email, 'admin_invite', [
            'firstName' => $admin->first_name,
            'inviteUrl' => rtrim((string) config('wedpluz.admin_origin'), '/').'/accept-invite?token='.$token,
            'hours' => $hours,
        ], 'admin-invite:'.$admin->id);
        $this->audit->log('admin.invited', 'access', $admin, $admin->email, null, ['roleIds' => $data['roleIds']]);

        return $this->created(AdminPresenter::admin($admin->refresh()));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['roleIds' => ['sometimes', 'array'], 'roleIds.*' => ['string', 'exists:admin_roles,id'], 'status' => ['sometimes', 'in:active,suspended']]);
        $admin = AdminUser::query()->with('roles')->findOrFail($id);
        $before = AdminPresenter::admin($admin);
        if ($admin->id === $this->admin()->id && ($data['status'] ?? null) === 'suspended') {
            throw ApiException::conflict('You cannot suspend yourself.');
        }

        DB::transaction(function () use ($admin, $data) {
            if (isset($data['roleIds'])) {
                $new = DB::table('admin_role_permissions')->whereIn('role_id', $data['roleIds'])->pluck('permission_key')->all();
                $this->assertGrantable(array_diff($new, $admin->permissionKeys()));
                $admin->roles()->sync($data['roleIds']);
            }
            if (isset($data['status'])) {
                if ($data['status'] === 'active' && $admin->status === 'invited') {
                    throw ApiException::conflict('Invited administrators become active when they accept their invitation.');
                }
                $admin->forceFill(['status' => $data['status']])->save();
                if ($data['status'] === 'suspended') {
                    PersonalAccessToken::query()->where('tokenable_type', 'admin_user')->where('tokenable_id', $admin->id)->delete();
                }
            }
            $this->assertRolesManagerRemains();
        });
        $admin->flushPermissionCache();
        $this->audit->log('admin.updated', 'access', $admin, $admin->email, $before, AdminPresenter::admin($admin->refresh()));

        return $this->ok(AdminPresenter::admin($admin));
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);
        $query = AuditLog::query()->with('admin')
            ->when($list->filter('module'), fn ($q, $m) => $q->where('module', $m))
            ->when($list->filter('actorId'), fn ($q, $a) => $q->where('admin_user_id', $a))
            ->when($list->filter('targetType'), fn ($q, $t) => $q->where('target_type', $t))
            ->when($list->filter('targetId'), fn ($q, $t) => $q->where('target_id', $t))
            ->when($list->searchPattern(), fn ($q, $p) => $q->where(fn ($w) => $w->where('action', 'ilike', $p)->orWhere('target_label', 'ilike', $p)->orWhere('correlation_id', 'ilike', $p)))
            ->when($list->dateFrom(), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($list->dateTo(), fn ($q, $d) => $q->where('created_at', '<=', $d));
        $list->applySort($query, ['createdAt' => 'created_at'], 'createdAt');

        return $this->paginated($list->paginate($query), fn (AuditLog $l) => AdminPresenter::audit($l));
    }

    public function securityLogs(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);
        $query = SecurityLog::query()->with('admin')
            ->when($list->filter('event'), fn ($q, $e) => $q->where('event', $e))
            ->when($list->searchPattern(), fn ($q, $p) => $q->where(fn ($w) => $w->where('email_attempted', 'ilike', $p)->orWhere('ip', 'ilike', $p)))
            ->when($list->dateFrom(), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($list->dateTo(), fn ($q, $d) => $q->where('created_at', '<=', $d));
        $list->applySort($query, ['createdAt' => 'created_at'], 'createdAt');

        return $this->paginated($list->paginate($query), fn (SecurityLog $l) => AdminPresenter::security($l));
    }

    /** @param list<string> $keys */
    private function assertGrantable(array $keys): void
    {
        $missing = array_diff($keys, $this->admin()->permissionKeys());
        if ($missing !== []) {
            throw ApiException::forbidden('You cannot grant permissions you do not hold: '.implode(', ', $missing).'.');
        }
    }

    private function assertRolesManagerRemains(): void
    {
        $holders = DB::table('admin_users')
            ->join('admin_user_roles', 'admin_user_roles.admin_user_id', '=', 'admin_users.id')
            ->join('admin_role_permissions', 'admin_role_permissions.role_id', '=', 'admin_user_roles.role_id')
            ->where('admin_users.status', 'active')->where('admin_role_permissions.permission_key', 'roles.manage')
            ->distinct()->count('admin_users.id');
        if ($holders === 0) {
            throw ApiException::conflict('At least one active administrator must keep the roles.manage permission.');
        }
    }
}
