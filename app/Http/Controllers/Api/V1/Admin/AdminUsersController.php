<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContentReportResource;
use App\Http\Resources\Fmt;
use App\Models\AccountDeletionRequest;
use App\Models\ContentReport;
use App\Models\Order;
use App\Models\User;
use App\Models\UserActivity;
use App\Services\Admin\AdminPresenter;
use App\Services\Admin\AuditLogger;
use App\Services\Auth\TokenService;
use App\Services\Users\AccountDeletionService;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminUsersController extends Controller
{
    public function __construct(private readonly AdminPresenter $presenter, private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);
        $query = User::query()->withTrashed()->whereNull('anonymized_at')
            ->when($list->searchPattern(), fn ($q, $p) => $q->where(fn ($w) => $w->where('email', 'ilike', $p)->orWhere('display_name', 'ilike', $p)
                ->orWhere('username', 'ilike', $p)->orWhere('phone', 'ilike', $p)))
            ->when($list->filter('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($list->filter('role'), fn ($q, $r) => $r === 'user' ? $q : $q->whereExists(fn ($e) => $e->from('user_roles')->whereColumn('user_roles.user_id', 'users.id')->where('role', $r)))
            ->when($list->filter('identityStatus'), fn ($q, $s) => $q->where('identity_status', $s))
            ->when($list->filter('plan'), fn ($q, $p) => $q->where('plan', $p))
            ->when($list->filter('source'), fn ($q, $s) => $q->where('signup_source', $s))
            ->when($list->dateFrom(), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($list->dateTo(), fn ($q, $d) => $q->where('created_at', '<=', $d));
        $list->applySort($query, ['createdAt' => 'created_at', 'name' => 'display_name', 'lastSeenAt' => 'last_seen_at', 'email' => 'email'], 'createdAt');
        $page = $list->paginate($query);

        return response()->json(['data' => $this->presenter->users($page->items()), 'meta' => ListQuery::meta($page)]);
    }

    public function show(string $id): JsonResponse
    {
        return $this->ok($this->presenter->user($this->find($id)));
    }

    public function activity(Request $request, string $id): JsonResponse
    {
        $user = $this->find($id);

        return $this->paginated(ListQuery::from($request)->paginate(UserActivity::query()->where('user_id', $user->id)->latest('created_at')), fn (UserActivity $a) => [
            'id' => $a->id, 'type' => $a->type, 'description' => $a->description, 'source' => $a->source, 'createdAt' => Fmt::date($a->created_at),
        ]);
    }

    public function tickets(Request $request, string $id): JsonResponse
    {
        $page = ListQuery::from($request)->paginate(Order::query()->where('user_id', $this->find($id)->id)->latest());

        return response()->json(['data' => $this->presenter->bookings($page->items()), 'meta' => ListQuery::meta($page)]);
    }

    public function reports(string $id): JsonResponse
    {
        $user = $this->find($id);
        $reports = ContentReport::query()->with(['reporter', 'reportedUser', 'assignee'])
            ->where(fn ($q) => $q->where('reported_user_id', $user->id)->orWhere('reporter_user_id', $user->id))->latest()->limit(200)->get();

        return $this->ok(ContentReportResource::collection($reports)->resolve());
    }

    public function suspend(Request $request, string $id, TokenService $tokens): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $user = $this->find($id);
        $before = ['status' => $user->status->value];
        DB::transaction(function () use ($user, $data, $tokens) {
            $user->forceFill(['status' => UserStatus::Suspended, 'suspended_reason' => $data['reason'], 'suspended_at' => now()])->save();
            $tokens->revokeAllForUser($user);
        });
        $this->audit->log('user.suspended', 'users', $user, $user->display_name, $before, ['status' => 'suspended', 'reason' => $data['reason']]);

        return $this->ok($this->presenter->user($user->refresh()), 'User suspended.');
    }

    public function reactivate(string $id): JsonResponse
    {
        $user = $this->find($id);
        if ($user->status !== UserStatus::Suspended) {
            throw ApiException::conflict('Only suspended users can be reactivated.');
        }
        $user->forceFill(['status' => UserStatus::Active, 'suspended_reason' => null, 'suspended_at' => null])->save();
        $this->audit->log('user.reactivated', 'users', $user, $user->display_name, ['status' => 'suspended'], ['status' => 'active']);

        return $this->ok($this->presenter->user($user->refresh()), 'User reactivated.');
    }

    public function roles(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['roles' => ['present', 'array'], 'roles.*' => ['in:user,vendor,organizer']]);
        $user = $this->find($id);
        $before = $user->roleList();
        $user->syncRoles(array_values(array_diff($data['roles'], ['user'])));
        $this->audit->log('user.roles_changed', 'users', $user, $user->display_name, ['roles' => $before], ['roles' => $user->roleList()]);

        return $this->ok($this->presenter->user($user->refresh()));
    }

    public function approveDeletion(string $id, AccountDeletionService $deletions): JsonResponse
    {
        $user = $this->find($id);
        $request = AccountDeletionRequest::query()->where('user_id', $user->id)->where('status', 'pending')->first()
            ?? throw ApiException::conflict('This user has no pending deletion request.');
        $request->forceFill(['decided_by_admin_id' => $this->admin()->id, 'decided_at' => now()])->save();
        $deletions->complete($request, $this->admin()->id);
        $this->audit->log('user.deletion_approved', 'users', $user, $user->display_name, null, ['deletionRequestId' => $request->id]);

        return $this->ok($this->presenter->user(User::withTrashed()->findOrFail($user->id)), 'Account deleted.');
    }

    public function rejectDeletion(string $id): JsonResponse
    {
        $user = $this->find($id);
        $request = AccountDeletionRequest::query()->where('user_id', $user->id)->where('status', 'pending')->first()
            ?? throw ApiException::conflict('This user has no pending deletion request.');
        DB::transaction(function () use ($request, $user) {
            $request->forceFill(['status' => 'cancelled', 'decided_by_admin_id' => $this->admin()->id, 'decided_at' => now()])->save();
            $user->forceFill(['status' => UserStatus::Active])->save();
        });
        $this->audit->log('user.deletion_rejected', 'users', $user, $user->display_name);

        return $this->ok($this->presenter->user($user->refresh()));
    }

    public function deletionRequests(Request $request): JsonResponse
    {
        $query = User::query()->withTrashed()->whereIn('id', AccountDeletionRequest::query()->where('status', 'pending')->select('user_id'))->latest('updated_at');
        $page = ListQuery::from($request)->paginate($query);

        return response()->json(['data' => $this->presenter->users($page->items()), 'meta' => ListQuery::meta($page)]);
    }

    private function find(string $id): User
    {
        return User::query()->withTrashed()->find($id) ?? throw ApiException::notFound('User not found.');
    }
}
