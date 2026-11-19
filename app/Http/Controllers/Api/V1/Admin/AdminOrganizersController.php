<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\VendorStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Integrations\Identity\IdentityResult;
use App\Models\CommissionRule;
use App\Models\Event;
use App\Models\IdentityVerification;
use App\Models\Settlement;
use App\Models\User;
use App\Models\VendorProfile;
use App\Services\Admin\AdminPresenter;
use App\Services\Admin\AuditLogger;
use App\Services\Notifications\NotificationService;
use App\Services\Users\IdentityVerificationService;
use App\Services\Vendors\VendorPresenter;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Organisers and vendors (records keyed by user id): verification decisions
 * (vendor profile and manual identity review), suspension, and per-organiser
 * commission overrides (`percent: null` reverts to category/platform rules).
 */
class AdminOrganizersController extends Controller
{
    public function __construct(private readonly AdminPresenter $presenter, private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);
        $type = $list->filter('type');
        $query = User::query()->withTrashed()->whereNull('anonymized_at')
            ->where(function ($q) use ($type) {
                $organizer = fn ($w) => $w->whereExists(fn ($e) => $e->from('events')->whereColumn('events.organizer_user_id', 'users.id'))
                    ->orWhereExists(fn ($e) => $e->from('user_roles')->whereColumn('user_roles.user_id', 'users.id')->where('role', 'organizer'));
                $vendor = fn ($w) => $w->whereExists(fn ($e) => $e->from('vendor_profiles')->whereColumn('vendor_profiles.user_id', 'users.id'));
                match ($type) {
                    'organizer' => $q->where($organizer),
                    'vendor' => $q->where($vendor),
                    'both' => $q->where($organizer)->where($vendor),
                    default => $q->where($organizer)->orWhere($vendor),
                };
            })
            ->when($list->searchPattern(), fn ($q, $p) => $q->where(fn ($w) => $w->where('display_name', 'ilike', $p)->orWhere('email', 'ilike', $p)
                ->orWhereExists(fn ($e) => $e->from('vendor_profiles')->whereColumn('vendor_profiles.user_id', 'users.id')->where('business_name', 'ilike', $p))))
            ->when($list->filter('vendorStatus'), fn ($q, $s) => $q->whereExists(fn ($e) => $e->from('vendor_profiles')->whereColumn('vendor_profiles.user_id', 'users.id')->where('status', $s)))
            ->when($list->filter('identityStatus'), fn ($q, $s) => $q->where('identity_status', $s));
        $list->applySort($query, ['joinedAt' => 'created_at', 'name' => 'display_name', 'createdAt' => 'created_at'], 'joinedAt');
        $page = $list->paginate($query);

        return response()->json(['data' => $this->presenter->organizers($page->items()), 'meta' => ListQuery::meta($page)]);
    }

    public function show(string $id, VendorPresenter $vendors): JsonResponse
    {
        $user = $this->find($id);
        $events = Event::withTrashed()->where('organizer_user_id', $user->id)->latest()->limit(20)->get();
        $settlements = Settlement::query()->with(['organizer', 'event'])->where('organizer_user_id', $user->id)->latest()->limit(20)->get();
        $vendor = VendorProfile::query()->where('user_id', $user->id)->first();

        return $this->ok($this->presenter->organizers([$user])[0] + [
            'events' => $this->presenter->events($events),
            'settlements' => $settlements->map(fn ($s) => AdminPresenter::settlement($s))->all(),
            'vendorProfile' => $vendor ? $vendors->detail($vendor, null) : null,
        ]);
    }

    public function verify(Request $request, string $id, IdentityVerificationService $identity, NotificationService $notifications): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $user = $this->find($id);
        $changes = [];

        DB::transaction(function () use ($user, &$changes) {
            $vendor = VendorProfile::query()->where('user_id', $user->id)->first();
            if ($vendor && in_array($vendor->status, [VendorStatus::PendingVerification, VendorStatus::Rejected, VendorStatus::Draft], true)) {
                $vendor->forceFill(['status' => VendorStatus::Active, 'verified_at' => now(), 'rejection_reason' => null])->save();
                DB::table('vendor_verification_documents')->where('user_id', $user->id)->where('status', 'pending')->update(['status' => 'approved', 'updated_at' => now()]);
                $changes['vendorStatus'] = 'active';
            }
        });

        $pending = IdentityVerification::query()->where('user_id', $user->id)->where('status', 'pending')->latest()->first();
        if ($pending) {
            $identity->apply($pending, new IdentityResult('verified', $pending->provider_reference ?? 'manual:'.$this->admin()->id));
            $changes['identityStatus'] = 'verified';
        }
        if ($changes === []) {
            throw ApiException::conflict('There is nothing awaiting verification for this account.');
        }
        if (isset($changes['vendorStatus'])) {
            $notifications->notify($user, 'system', ['title' => 'Business verified', 'body' => 'Your business profile is verified and now visible to customers.']);
        }
        $this->audit->log('organizer.verified', 'organizers', $user, $user->display_name, null, $changes + ['reason' => $data['reason'] ?? null]);

        return $this->ok($this->presenter->organizers([$user->refresh()])[0], 'Verified.');
    }

    public function reject(Request $request, string $id, IdentityVerificationService $identity, NotificationService $notifications): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $user = $this->find($id);
        $vendor = VendorProfile::query()->where('user_id', $user->id)->first();
        if ($vendor && $vendor->status === VendorStatus::PendingVerification) {
            $vendor->forceFill(['status' => VendorStatus::Rejected, 'rejection_reason' => $data['reason']])->save();
            DB::table('vendor_verification_documents')->where('user_id', $user->id)->where('status', 'pending')->update(['status' => 'rejected', 'updated_at' => now()]);
            $notifications->notify($user, 'system', ['title' => 'Business verification declined', 'body' => 'Your business profile was not verified: '.$data['reason']]);
        }
        $pending = IdentityVerification::query()->where('user_id', $user->id)->where('status', 'pending')->latest()->first();
        if ($pending) {
            $identity->apply($pending, new IdentityResult('failed', $pending->provider_reference, null, $data['reason']));
        }
        $this->audit->log('organizer.rejected', 'organizers', $user, $user->display_name, null, ['reason' => $data['reason']]);

        return $this->ok($this->presenter->organizers([$user->refresh()])[0]);
    }

    public function suspend(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $user = $this->find($id);
        DB::transaction(function () use ($user, $data) {
            VendorProfile::query()->where('user_id', $user->id)->update(['status' => VendorStatus::Suspended->value, 'suspended_reason' => $data['reason']]);
            Settlement::query()->where('organizer_user_id', $user->id)->whereIn('status', ['pending', 'ready'])->whereNull('payout_id')
                ->update(['status' => 'on_hold', 'hold_reason' => 'Organiser suspended: '.$data['reason']]);
        });
        $this->audit->log('organizer.suspended', 'organizers', $user, $user->display_name, null, ['reason' => $data['reason']]);

        return $this->ok($this->presenter->organizers([$user->refresh()])[0], 'Suspended. Their settlements are on hold.');
    }

    public function reactivate(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $user = $this->find($id);
        VendorProfile::query()->where('user_id', $user->id)->where('status', VendorStatus::Suspended->value)
            ->update(['status' => VendorStatus::Active->value, 'suspended_reason' => null]);
        $this->audit->log('organizer.reactivated', 'organizers', $user, $user->display_name, null, ['reason' => $data['reason'] ?? null]);

        return $this->ok($this->presenter->organizers([$user->refresh()])[0], 'Reactivated. Release held settlements from Finance.');
    }

    public function commission(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['percent' => ['present', 'nullable', 'numeric', 'min:0', 'max:50']]);
        $user = $this->find($id);
        $before = CommissionRule::query()->where('scope', 'organizer')->where('scope_id', $user->id)->where('is_active', true)->value('percent');

        DB::transaction(function () use ($user, $data) {
            CommissionRule::query()->where('scope', 'organizer')->where('scope_id', $user->id)->where('is_active', true)->update(['is_active' => false]);
            if ($data['percent'] !== null) {
                CommissionRule::query()->create([
                    'scope' => 'organizer', 'scope_id' => $user->id, 'scope_label' => $user->display_name,
                    'percent' => $data['percent'], 'fixed_fee' => 0, 'currency' => 'NGN',
                    'effective_from' => now()->toDateString(), 'updated_by_admin_id' => $this->admin()->id,
                ]);
            }
        });
        $this->audit->log('organizer.commission_changed', 'finance', $user, $user->display_name, ['percent' => $before], ['percent' => $data['percent']]);

        return $this->ok($this->presenter->organizers([$user])[0]);
    }

    private function find(string $id): User
    {
        return User::query()->withTrashed()->find($id) ?? throw ApiException::notFound('Organizer not found.');
    }
}
