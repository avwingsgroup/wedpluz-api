<?php

namespace App\Services\Admin;

use App\Enums\AdminPermission;
use App\Http\Resources\Fmt;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Banner;
use App\Models\CommissionRule;
use App\Models\Community;
use App\Models\Dispute;
use App\Models\Event;
use App\Models\NotificationCampaign;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\SecurityLog;
use App\Models\Settlement;
use App\Models\User;
use App\Models\VendorProfile;
use App\Services\Events\EventPresenter;
use App\Support\Models;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Admin console record shapes (AdminUserRecord, AdminEventRecord, ...). */
class AdminPresenter
{
    public function __construct(private readonly EventPresenter $events) {}

    /** @param iterable<User> $users */
    public function users(iterable $users): array
    {
        $users = Models::collect($users);
        $ids = $users->pluck('id');
        $tickets = DB::table('tickets')->whereIn('holder_user_id', $ids)->whereIn('status', ['valid', 'checked_in'])->selectRaw('holder_user_id, count(*) c')->groupBy('holder_user_id')->pluck('c', 'holder_user_id');
        $events = DB::table('events')->whereIn('organizer_user_id', $ids)->whereNull('deleted_at')->selectRaw('organizer_user_id, count(*) c')->groupBy('organizer_user_id')->pluck('c', 'organizer_user_id');
        $reports = DB::table('content_reports')->whereIn('reported_user_id', $ids)->selectRaw('reported_user_id, count(*) c')->groupBy('reported_user_id')->pluck('c', 'reported_user_id');
        $spent = DB::table('orders')->whereIn('user_id', $ids)->whereNotNull('paid_at')->selectRaw('user_id, sum(total - refunded_amount) s')->groupBy('user_id')->pluck('s', 'user_id');
        $roles = DB::table('user_roles')->whereIn('user_id', $ids)->get()->groupBy('user_id');
        $interests = DB::table('user_interests')->whereIn('user_id', $ids)->get()->groupBy('user_id');
        $vendors = DB::table('vendor_profiles')->whereIn('user_id', $ids)->whereNull('deleted_at')->pluck('id', 'user_id');
        $wallets = DB::table('wallets')->whereIn('user_id', $ids)->pluck('is_active', 'user_id');
        $deletions = DB::table('account_deletion_requests')->whereIn('user_id', $ids)->orderBy('created_at')->get()->keyBy('user_id');

        return $users->map(function (User $u) use ($tickets, $events, $reports, $spent, $roles, $interests, $vendors, $wallets, $deletions) {
            $deletion = $deletions[$u->id] ?? null;

            return Fmt::user($u) + [
                'firstName' => $u->first_name,
                'lastName' => $u->last_name,
                'email' => $u->email,
                'phone' => $u->phone,
                'dateOfBirth' => Fmt::day($u->date_of_birth),
                'emailVerifiedAt' => Fmt::date($u->email_verified_at),
                'phoneVerifiedAt' => Fmt::date($u->phone_verified_at),
                'identityStatus' => $u->identity_status->value,
                'interests' => collect($interests[$u->id] ?? [])->pluck('interest_slug')->all(),
                'roles' => array_values(array_unique(['user', ...collect($roles[$u->id] ?? [])->pluck('role')->all()])),
                'status' => $u->status->value,
                'plan' => $u->plan,
                'locale' => $u->locale,
                'city' => $u->city,
                'countryCode' => $u->country_code,
                'businessProfileId' => $vendors[$u->id] ?? null,
                'walletEnabled' => (bool) ($wallets[$u->id] ?? false),
                'createdAt' => Fmt::date($u->created_at),
                'lastSeenAt' => Fmt::date($u->last_seen_at),
                'ticketsCount' => (int) ($tickets[$u->id] ?? 0),
                'eventsCount' => (int) ($events[$u->id] ?? 0),
                'reportsCount' => (int) ($reports[$u->id] ?? 0),
                'totalSpent' => (int) ($spent[$u->id] ?? 0),
                'signupSource' => $u->signup_source,
                'suspendedReason' => $u->suspended_reason,
                'deletionRequest' => $deletion ? [
                    'requestedAt' => Fmt::date(CarbonImmutable::parse($deletion->requested_at)),
                    'scheduledFor' => Fmt::date(CarbonImmutable::parse($deletion->scheduled_for)),
                    'status' => $deletion->status,
                ] : null,
            ];
        })->values()->all();
    }

    public function user(User $user): array
    {
        return $this->users([$user])[0];
    }

    /** @param iterable<Event> $events */
    public function events(iterable $events): array
    {
        $events = Models::collect($events);
        $events->loadMissing(['reviewedBy']);
        $summaries = $this->events->summaries($events, null, true);
        $revenue = DB::table('orders')->whereIn('event_id', $events->pluck('id'))->whereNotNull('paid_at')
            ->selectRaw('event_id, sum(subtotal - discount - refunded_amount) s')->groupBy('event_id')->pluck('s', 'event_id');
        $reports = DB::table('content_reports')->where('target_type', 'event')->whereIn('target_id', $events->pluck('id'))
            ->selectRaw('target_id, count(*) c')->groupBy('target_id')->pluck('c', 'target_id');

        return $events->values()->map(fn (Event $e, int $i) => $summaries[$i] + [
            'ticketsSold' => $e->tickets_sold,
            'revenue' => max(0, (int) ($revenue[$e->id] ?? 0)),
            'reportsCount' => (int) ($reports[$e->id] ?? 0),
            'submittedAt' => Fmt::date($e->submitted_at),
            'reviewedBy' => $e->reviewedBy ? ['id' => $e->reviewedBy->id, 'displayName' => $e->reviewedBy->fullName(), 'avatarUrl' => $e->reviewedBy->avatar_url] : null,
            'rejectionReason' => $e->rejection_reason,
            'source' => $e->source,
        ])->all();
    }

    public function event(Event $event): array
    {
        return $this->events([$event])[0];
    }

    /** @param iterable<User> $users Organizer/vendor accounts */
    public function organizers(iterable $users): array
    {
        $users = Models::collect($users);
        $ids = $users->pluck('id');
        $vendors = VendorProfile::withTrashed()->whereIn('user_id', $ids)->get()->keyBy('user_id');
        $events = DB::table('events')->whereIn('organizer_user_id', $ids)->whereNull('deleted_at')->selectRaw('organizer_user_id, count(*) c, sum(tickets_sold) t')->groupBy('organizer_user_id')->get()->keyBy('organizer_user_id');
        $gross = DB::table('orders')->join('events', 'events.id', '=', 'orders.event_id')->whereIn('events.organizer_user_id', $ids)->whereNotNull('orders.paid_at')
            ->selectRaw('events.organizer_user_id, sum(orders.subtotal - orders.discount) s')->groupBy('events.organizer_user_id')->pluck('s', 'organizer_user_id');
        $pending = DB::table('settlements')->whereIn('organizer_user_id', $ids)->whereIn('status', ['pending', 'ready', 'on_hold'])->selectRaw('organizer_user_id, sum(net_payable) s')->groupBy('organizer_user_id')->pluck('s', 'organizer_user_id');
        $paid = DB::table('payouts')->whereIn('organizer_user_id', $ids)->where('status', 'paid')->selectRaw('organizer_user_id, sum(amount) s')->groupBy('organizer_user_id')->pluck('s', 'organizer_user_id');
        $overrides = CommissionRule::query()->where('scope', 'organizer')->where('is_active', true)->whereIn('scope_id', $ids)->pluck('percent', 'scope_id');
        $docs = DB::table('vendor_verification_documents')->whereIn('user_id', $ids)->get()->groupBy('user_id');

        return $users->map(function (User $u) use ($vendors, $events, $gross, $pending, $paid, $overrides, $docs) {
            $vendor = $vendors[$u->id] ?? null;
            $hasEvents = (int) ($events[$u->id]->c ?? 0) > 0 || in_array('organizer', $u->roleList(), true);

            return [
                'id' => $u->id,
                'user' => Fmt::userWithEmail($u),
                'businessName' => $vendor?->business_name,
                'type' => $vendor && $hasEvents ? 'both' : ($vendor ? 'vendor' : 'organizer'),
                'vendorStatus' => $vendor?->status->value,
                'identityStatus' => $u->identity_status->value,
                'eventsCount' => (int) ($events[$u->id]->c ?? 0),
                'ticketsSold' => (int) ($events[$u->id]->t ?? 0),
                'grossRevenue' => (int) ($gross[$u->id] ?? 0),
                'commissionPercent' => isset($overrides[$u->id]) ? (float) $overrides[$u->id] : null,
                'pendingSettlement' => (int) ($pending[$u->id] ?? 0),
                'totalPaidOut' => (int) ($paid[$u->id] ?? 0),
                'rating' => $vendor ? round((float) $vendor->rating_avg, 1) : null,
                'joinedAt' => Fmt::date($u->created_at),
                'verificationDocuments' => collect($docs[$u->id] ?? [])->map(fn ($d) => ['id' => $d->id, 'type' => $d->type, 'url' => route('files.private', ['upload' => $d->upload_id]), 'status' => $d->status])->values()->all(),
            ];
        })->values()->all();
    }

    /** @param iterable<Order> $orders */
    public function bookings(iterable $orders): array
    {
        $orders = Models::collect($orders);
        $orders->loadMissing(['event', 'user']);
        $payments = Payment::query()->where('payable_type', 'order')->whereIn('payable_id', $orders->pluck('id'))->latest()->get()->unique('payable_id')->keyBy('payable_id');
        $tickets = DB::table('tickets')->whereIn('order_id', $orders->pluck('id'))->selectRaw("order_id, count(*) c, count(*) filter (where status='checked_in') k")->groupBy('order_id')->get()->keyBy('order_id');
        $disputes = DB::table('disputes')->whereIn('order_id', $orders->pluck('id'))->orderBy('created_at')->pluck('id', 'order_id');

        return $orders->map(fn (Order $o) => [
            'id' => $o->id,
            'reference' => $o->reference,
            'event' => ['id' => $o->event->id, 'slug' => $o->event->slug, 'title' => $o->event->title, 'startsAt' => Fmt::date($o->event->starts_at) ?? Fmt::date($o->event->created_at), 'coverUrl' => $o->event->cover_url ?: EventPresenter::placeholderCover()],
            'buyer' => Fmt::userWithEmail($o->user),
            'ticketsCount' => (int) ($tickets[$o->id]->c ?? 0),
            'total' => $o->total,
            'serviceFee' => $o->service_fee,
            'currency' => $o->currency,
            'status' => $o->status->value,
            'paymentMethod' => $o->payment_method,
            'provider' => $o->provider,
            'providerReference' => $payments[$o->id]->provider_transaction_id ?? null,
            'checkedInCount' => (int) ($tickets[$o->id]->k ?? 0),
            'source' => $o->source,
            'createdAt' => Fmt::date($o->created_at),
            'disputeId' => $disputes[$o->id] ?? null,
        ])->values()->all();
    }

    public static function dispute(Dispute $d): array
    {
        $order = $d->order;

        return [
            'id' => $d->id,
            'booking' => ['id' => $order->id, 'reference' => $order->reference, 'total' => $order->total, 'currency' => $order->currency],
            'raisedBy' => Fmt::user($d->raisedBy) ?? ['id' => '', 'displayName' => 'Payment provider', 'avatarUrl' => null],
            'type' => $d->type,
            'status' => $d->status,
            'description' => $d->description,
            'assignedTo' => Fmt::admin($d->assignee),
            'createdAt' => Fmt::date($d->created_at),
            'updatedAt' => Fmt::date($d->updated_at),
        ];
    }

    public static function refund(Refund $r): array
    {
        $order = $r->order;

        return [
            'id' => $r->id,
            'reference' => $r->reference,
            'booking' => [
                'id' => $order->id,
                'reference' => $order->reference,
                'event' => ['id' => $order->event->id, 'slug' => $order->event->slug, 'title' => $order->event->title, 'startsAt' => Fmt::date($order->event->starts_at) ?? Fmt::date($order->event->created_at), 'coverUrl' => $order->event->cover_url ?: EventPresenter::placeholderCover()],
                'buyer' => Fmt::userWithEmail($order->user),
            ],
            'amount' => $r->amount,
            'currency' => $r->currency,
            'reason' => $r->reason,
            'status' => $r->status->value,
            'requestedAt' => Fmt::date($r->created_at),
            'decidedBy' => Fmt::admin($r->decidedBy),
            'decidedAt' => Fmt::date($r->decided_at),
            'provider' => $r->provider,
        ];
    }

    public static function settlement(Settlement $s): array
    {
        $organizer = $s->organizer;

        return [
            'id' => $s->id,
            'reference' => $s->reference,
            'organizer' => (Fmt::userWithEmail($organizer) ?? []) + ['businessName' => VendorProfile::query()->where('user_id', $s->organizer_user_id)->value('business_name')],
            'event' => ['id' => $s->event->id, 'title' => $s->event->title, 'startsAt' => Fmt::date($s->event->starts_at) ?? Fmt::date($s->event->created_at)],
            'grossSales' => $s->gross_sales,
            'refunds' => $s->refunds,
            'platformCommission' => $s->platform_commission,
            'gatewayFees' => $s->gateway_fees,
            'netPayable' => $s->net_payable,
            'currency' => $s->currency,
            'status' => $s->status->value,
            'holdReason' => $s->hold_reason ?? $s->pending_reason,
            'eligibleAt' => Fmt::date($s->eligible_at),
            'paidAt' => Fmt::date($s->paid_at),
            'payoutId' => $s->payout_id,
        ];
    }

    public static function payout(Payout $p): array
    {
        $bank = $p->bank_account_snapshot ?? [];

        return [
            'id' => $p->id,
            'reference' => $p->reference,
            'settlementIds' => DB::table('payout_settlements')->where('payout_id', $p->id)->pluck('settlement_id')->all(),
            'organizer' => (Fmt::user($p->organizer) ?? []) + ['businessName' => VendorProfile::query()->where('user_id', $p->organizer_user_id)->value('business_name')],
            'bankAccount' => ['bankName' => $bank['bankName'] ?? '', 'accountNumber' => '******'.($bank['accountLast4'] ?? ''), 'accountName' => $bank['accountName'] ?? ''],
            'amount' => $p->amount,
            'currency' => $p->currency,
            'provider' => in_array($p->provider, ['paystack', 'monnify'], true) ? $p->provider : 'manual',
            'status' => $p->status->value,
            'requestedBy' => Fmt::admin($p->requestedBy),
            'approvedBy' => Fmt::admin($p->approvedBy),
            'failureReason' => $p->failure_reason,
            'createdAt' => Fmt::date($p->created_at),
            'completedAt' => Fmt::date($p->completed_at),
        ];
    }

    /** FinanceTransaction from a payment row. */
    public static function transaction(Payment $p): array
    {
        $type = match ($p->purpose) {
            'ticket_sale' => 'ticket_sale', 'top_up' => 'top_up', 'subscription' => 'subscription', default => 'ticket_sale'
        };
        $event = $p->payable_type === 'order' ? DB::table('orders')->join('events', 'events.id', '=', 'orders.event_id')->where('orders.id', $p->payable_id)->select('events.id', 'events.title')->first() : null;
        $status = match ($p->status->value) {
            'succeeded', 'refunded', 'partially_refunded' => 'successful',
            'failed', 'mismatch' => 'failed',
            'pending', 'initialized' => 'pending',
            default => 'processing',
        };

        return [
            'id' => $p->id,
            'reference' => $p->provider_reference,
            'providerReference' => $p->provider_transaction_id,
            'type' => $type,
            'provider' => in_array($p->provider, ['paystack', 'stripe', 'paypal', 'monnify', 'wallet'], true) ? $p->provider : 'internal',
            'amount' => $p->amount,
            'fee' => $p->fee,
            'net' => $p->amount - $p->fee,
            'currency' => $p->currency,
            'status' => $status,
            'reconciliationStatus' => $p->reconciliation_status,
            'user' => Fmt::user($p->user),
            'event' => $event ? ['id' => $event->id, 'title' => $event->title] : null,
            'createdAt' => Fmt::date($p->created_at),
            'settledAt' => Fmt::date($p->settled_at ?? $p->verified_at),
        ];
    }

    public static function commission(CommissionRule $r): array
    {
        return [
            'id' => $r->id,
            'scope' => $r->scope,
            'scopeId' => $r->scope_id,
            'scopeLabel' => $r->scope_label,
            'percent' => (float) $r->percent,
            'fixedFee' => $r->fixed_fee,
            'currency' => $r->currency,
            'effectiveFrom' => $r->effective_from?->format('Y-m-d'),
            'updatedBy' => Fmt::admin($r->updatedBy),
            'updatedAt' => Fmt::date($r->updated_at),
        ];
    }

    public static function community(Community $c, array $stats): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'avatarUrl' => $c->avatar_url,
            'event' => $c->event ? ['id' => $c->event->id, 'title' => $c->event->title] : null,
            'owner' => Fmt::user($c->owner),
            'membersCount' => (int) ($stats['members'] ?? 0),
            'messagesLast7d' => (int) ($stats['messages'] ?? 0),
            'reportsOpen' => (int) ($stats['reports'] ?? 0),
            'status' => $c->status->value,
            'createdAt' => Fmt::date($c->created_at),
        ];
    }

    public static function campaign(NotificationCampaign $c): array
    {
        $stats = array_replace(['sent' => 0, 'delivered' => 0, 'opened' => 0, 'clicked' => 0, 'failed' => 0], $c->stats ?? []);

        return [
            'id' => $c->id,
            'name' => $c->name,
            'channels' => array_values($c->channels ?? []),
            'audience' => array_replace(['type' => 'all', 'filters' => (object) [], 'estimatedSize' => 0], $c->audience ?? []),
            'templateId' => $c->template_id,
            'subject' => $c->subject,
            'body' => $c->body,
            'status' => $c->status,
            'scheduledAt' => Fmt::date($c->scheduled_at),
            'sentAt' => Fmt::date($c->sent_at),
            'stats' => array_map('intval', $stats),
            'createdBy' => Fmt::admin($c->createdBy) ?? ['id' => '', 'firstName' => 'System', 'lastName' => ''],
            'createdAt' => Fmt::date($c->created_at),
        ];
    }

    public static function template(NotificationTemplate $t): array
    {
        return [
            'id' => $t->id, 'key' => $t->key, 'name' => $t->name, 'channel' => $t->channel, 'subject' => $t->subject,
            'body' => $t->body, 'variables' => array_values($t->variables ?? []), 'isActive' => $t->is_active, 'updatedAt' => Fmt::date($t->updated_at),
        ];
    }

    public static function banner(Banner $b): array
    {
        return [
            'id' => $b->id, 'title' => $b->title, 'subtitle' => $b->subtitle, 'imageUrl' => $b->image_url, 'ctaLabel' => $b->cta_label,
            'ctaUrl' => $b->cta_url, 'placement' => $b->placement, 'audience' => $b->audience, 'startsAt' => Fmt::date($b->starts_at),
            'endsAt' => Fmt::date($b->ends_at), 'isActive' => $b->is_active, 'priority' => $b->priority, 'impressions' => $b->impressions, 'clicks' => $b->clicks,
        ];
    }

    public static function role(AdminRole $r): array
    {
        return [
            'id' => $r->id, 'name' => $r->name, 'slug' => $r->slug, 'description' => (string) $r->description,
            'permissions' => $r->permissionKeys(), 'isSystem' => $r->is_system,
            'membersCount' => DB::table('admin_user_roles')->where('role_id', $r->id)->count(),
            'createdAt' => Fmt::date($r->created_at),
        ];
    }

    public static function admin(AdminUser $a): array
    {
        $a->loadMissing('roles');

        return [
            'id' => $a->id, 'firstName' => $a->first_name, 'lastName' => $a->last_name, 'email' => $a->email, 'avatarUrl' => $a->avatar_url,
            'roles' => $a->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'slug' => $r->slug])->values()->all(),
            'permissions' => $a->permissionKeys(), 'status' => $a->status, 'twoFactorEnabled' => $a->two_factor_enabled,
            'lastLoginAt' => Fmt::date($a->last_login_at), 'createdAt' => Fmt::date($a->created_at),
        ];
    }

    public static function audit(AuditLog $l): array
    {
        $admin = $l->admin;

        return [
            'id' => $l->id,
            'actor' => $admin ? ['id' => $admin->id, 'firstName' => $admin->first_name, 'lastName' => $admin->last_name, 'email' => $admin->email, 'avatarUrl' => $admin->avatar_url] : null,
            'action' => $l->action, 'module' => $l->module, 'targetType' => (string) $l->target_type, 'targetId' => $l->target_id,
            'targetLabel' => $l->target_label, 'before' => $l->before, 'after' => $l->after, 'ip' => (string) $l->ip,
            'userAgent' => (string) $l->user_agent, 'correlationId' => (string) $l->correlation_id, 'createdAt' => Fmt::date($l->created_at),
        ];
    }

    public static function security(SecurityLog $l): array
    {
        return [
            'id' => $l->id,
            'actor' => $l->admin_user_id ? ['id' => $l->admin_user_id, 'email' => (string) ($l->admin?->email ?? $l->email_attempted)] : ($l->email_attempted ? ['id' => '', 'email' => $l->email_attempted] : null),
            'event' => $l->event, 'ip' => (string) $l->ip, 'location' => $l->location, 'userAgent' => (string) $l->user_agent, 'createdAt' => Fmt::date($l->created_at),
        ];
    }

    /** Permission catalogue. */
    public static function permissions(): array
    {
        return array_map(fn (AdminPermission $p) => ['key' => $p->value] + $p->meta(), AdminPermission::cases());
    }
}
