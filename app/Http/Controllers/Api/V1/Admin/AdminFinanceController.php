<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\CommissionRule;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\ReconciliationRecord;
use App\Models\Refund;
use App\Models\Settlement;
use App\Services\Admin\AdminPresenter;
use App\Services\Admin\AuditLogger;
use App\Services\Admin\MetricsService;
use App\Services\Checkout\RefundService;
use App\Services\Finance\LedgerService;
use App\Services\Finance\PayoutService;
use App\Services\Finance\SettlementService;
use App\Support\ListQuery;
use App\Support\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AdminFinanceController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function summary(Request $request, MetricsService $metrics, LedgerService $ledger): JsonResponse
    {
        [$from, $to] = $metrics->period($request->query('from'), $request->query('to'));
        $key = 'admin:finance_summary:'.$from->timestamp.':'.$to->timestamp;

        return $this->ok(Cache::remember($key, 600, function () use ($metrics, $from, $to) {
            $paid = fn ($q) => $q->where('payable_type', 'order')->where('status', 'succeeded');
            $tz = str_replace("'", '', Platform::timezone());

            return [
                'period' => ['from' => $from->setTimezone($tz)->toDateString(), 'to' => $to->setTimezone($tz)->toDateString()],
                'currency' => 'NGN',
                'gtv' => $metrics->kpi('payments', 'verified_at', 'sum(amount)', $from, $to, $paid),
                'platformRevenue' => $metrics->kpi('orders', 'paid_at', 'sum(service_fee)', $from, $to, fn ($q) => $q->whereNotNull('paid_at')),
                'gatewayFees' => (int) DB::table('payments')->where('status', 'succeeded')->whereBetween('verified_at', [$from, $to])->sum('fee'),
                'refunds' => (int) DB::table('refunds')->where('status', 'completed')->whereBetween('completed_at', [$from, $to])->sum('amount'),
                'pendingSettlements' => (int) DB::table('settlements')->whereIn('status', ['pending', 'ready', 'on_hold'])->sum('net_payable'),
                'paidOut' => (int) DB::table('payouts')->where('status', 'paid')->whereBetween('completed_at', [$from, $to])->sum('amount'),
                'walletFloat' => (int) DB::table('wallets')->sum('cached_balance'),
                'byProvider' => DB::table('payments')->whereBetween('created_at', [$from, $to])->groupBy('provider')
                    ->selectRaw("provider, coalesce(sum(amount) filter (where status='succeeded'),0) volume, count(*) filter (where status='succeeded') c, count(*) n")->get()
                    ->map(fn ($r) => ['provider' => $r->provider, 'volume' => (int) $r->volume, 'count' => (int) $r->c, 'successRate' => $r->n > 0 ? round($r->c / $r->n * 100, 1) : 0])->all(),
                'revenueByDay' => DB::table('orders')->whereNotNull('paid_at')->whereBetween('paid_at', [$from, $to])
                    ->selectRaw("(paid_at at time zone '$tz')::date d, sum(total) gtv, sum(service_fee) revenue")->groupBy('d')->orderBy('d')->get()
                    ->map(fn ($r) => ['date' => (string) $r->d, 'gtv' => (int) $r->gtv, 'revenue' => (int) $r->revenue])->all(),
            ];
        }));
    }

    public function transactions(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);
        $typeToPurpose = ['ticket_sale' => 'ticket_sale', 'top_up' => 'top_up', 'subscription' => 'subscription'];
        $statusMap = ['successful' => ['succeeded', 'refunded', 'partially_refunded'], 'failed' => ['failed', 'mismatch'], 'pending' => ['pending', 'initialized'], 'processing' => ['pending']];
        $query = Payment::query()->with('user')
            ->when($list->searchPattern(), fn ($q, $p) => $q->where(fn ($w) => $w->where('provider_reference', 'ilike', $p)->orWhere('provider_transaction_id', 'ilike', $p)))
            ->when($list->filter('type'), fn ($q, $t) => $q->where('purpose', $typeToPurpose[$t] ?? '__none__'))
            ->when($list->filter('provider'), fn ($q, $p) => $q->where('provider', $p))
            ->when($list->filter('status'), fn ($q, $s) => $q->whereIn('status', $statusMap[$s] ?? [$s]))
            ->when($list->filter('reconciliationStatus'), fn ($q, $r) => $q->where('reconciliation_status', $r))
            ->when($list->dateFrom(), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($list->dateTo(), fn ($q, $d) => $q->where('created_at', '<=', $d));
        $list->applySort($query, ['createdAt' => 'created_at', 'amount' => 'amount'], 'createdAt');

        return $this->paginated($list->paginate($query), fn (Payment $p) => AdminPresenter::transaction($p));
    }

    public function reconcile(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:matched,mismatch'], 'note' => ['nullable', 'string', 'max:1000']]);
        $payment = Payment::query()->with('user')->findOrFail($id);
        $before = $payment->reconciliation_status;
        DB::transaction(function () use ($payment, $data) {
            $payment->forceFill(['reconciliation_status' => $data['status'], 'reconciliation_note' => $data['note'] ?? null])->save();
            ReconciliationRecord::query()->create(['payment_id' => $payment->id, 'provider' => $payment->provider, 'status' => $data['status'], 'note' => $data['note'] ?? null, 'resolved_by_admin_id' => $this->admin()->id]);
        });
        $this->audit->log('transaction.reconciled', 'finance', $payment, $payment->provider_reference, ['reconciliationStatus' => $before], $data);

        return $this->ok(AdminPresenter::transaction($payment));
    }

    public function settlements(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);
        $query = Settlement::query()->with(['organizer', 'event'])
            ->when($list->filter('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($list->filter('organizerId'), fn ($q, $o) => $q->where('organizer_user_id', $o))
            ->when($list->searchPattern(), fn ($q, $p) => $q->where(fn ($w) => $w->where('reference', 'ilike', $p)->orWhereHas('event', fn ($e) => $e->where('title', 'ilike', $p))))
            ->when($list->dateFrom(), fn ($q, $d) => $q->where('eligible_at', '>=', $d))
            ->when($list->dateTo(), fn ($q, $d) => $q->where('eligible_at', '<=', $d));
        $list->applySort($query, ['eligibleAt' => 'eligible_at', 'netPayable' => 'net_payable', 'createdAt' => 'created_at'], 'eligibleAt');

        return $this->paginated($list->paginate($query), fn (Settlement $s) => AdminPresenter::settlement($s));
    }

    public function hold(Request $request, string $id, SettlementService $settlements): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $settlement = $settlements->hold(Settlement::query()->findOrFail($id), $data['reason']);
        $this->audit->log('settlement.held', 'finance', $settlement, $settlement->reference, null, $data);

        return $this->ok(AdminPresenter::settlement($settlement->load(['organizer', 'event'])));
    }

    public function release(string $id, SettlementService $settlements): JsonResponse
    {
        $settlement = $settlements->release(Settlement::query()->findOrFail($id));
        $this->audit->log('settlement.released', 'finance', $settlement, $settlement->reference);

        return $this->ok(AdminPresenter::settlement($settlement->load(['organizer', 'event'])));
    }

    public function payouts(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);
        $query = Payout::query()->with(['organizer', 'requestedBy', 'approvedBy'])
            ->when($list->filter('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($list->searchPattern(), fn ($q, $p) => $q->where('reference', 'ilike', $p))
            ->when($list->dateFrom(), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($list->dateTo(), fn ($q, $d) => $q->where('created_at', '<=', $d));
        $list->applySort($query, ['createdAt' => 'created_at', 'amount' => 'amount'], 'createdAt');

        return $this->paginated($list->paginate($query), fn (Payout $p) => AdminPresenter::payout($p));
    }

    public function createPayout(Request $request, PayoutService $payouts): JsonResponse
    {
        $data = $request->validate(['settlementIds' => ['required', 'array', 'min:1', 'max:100'], 'settlementIds.*' => ['string', 'max:40']]);
        $payout = $payouts->create($data['settlementIds'], $this->admin());
        $this->audit->log('payout.created', 'finance', $payout, $payout->reference, null, ['settlementIds' => $data['settlementIds'], 'amount' => $payout->amount]);

        return $this->ok(AdminPresenter::payout($payout->load(['organizer', 'requestedBy', 'approvedBy'])), 'Payout created. A different admin must approve it.');
    }

    public function approvePayout(Request $request, string $id, PayoutService $payouts): JsonResponse
    {
        $data = $request->validate(['otp' => ['required', 'string', 'max:10']]);
        $payout = $payouts->approve(Payout::query()->findOrFail($id), $this->admin(), $data['otp']);
        $this->audit->log('payout.approved', 'finance', $payout, $payout->reference, ['status' => 'requested'], ['status' => $payout->status->value]);

        return $this->ok(AdminPresenter::payout($payout->load(['organizer', 'requestedBy', 'approvedBy'])), 'Payout approved and sent.');
    }

    public function rejectPayout(Request $request, string $id, PayoutService $payouts): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $payout = $payouts->reject(Payout::query()->findOrFail($id), $this->admin(), $data['reason']);
        $this->audit->log('payout.rejected', 'finance', $payout, $payout->reference, null, $data);

        return $this->ok(AdminPresenter::payout($payout->load(['organizer', 'requestedBy', 'approvedBy'])));
    }

    public function refunds(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);
        $query = Refund::query()->with(['order.event', 'order.user', 'decidedBy'])
            ->when($list->filter('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($list->searchPattern(), fn ($q, $p) => $q->where(fn ($w) => $w->where('reference', 'ilike', $p)->orWhereHas('order', fn ($o) => $o->where('reference', 'ilike', $p))))
            ->when($list->dateFrom(), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($list->dateTo(), fn ($q, $d) => $q->where('created_at', '<=', $d));
        $list->applySort($query, ['createdAt' => 'created_at', 'requestedAt' => 'created_at', 'amount' => 'amount'], 'createdAt');

        return $this->paginated($list->paginate($query), fn (Refund $r) => AdminPresenter::refund($r));
    }

    public function approveRefund(string $id, RefundService $refunds): JsonResponse
    {
        $refund = $refunds->approve(Refund::query()->findOrFail($id), $this->admin());
        $this->audit->log('refund.approved', 'refunds', $refund, $refund->reference, ['status' => 'requested'], ['status' => $refund->status->value]);

        return $this->ok(AdminPresenter::refund($refund->load(['order.event', 'order.user', 'decidedBy'])));
    }

    public function rejectRefund(Request $request, string $id, RefundService $refunds): JsonResponse
    {
        $refund = $refunds->reject(Refund::query()->findOrFail($id), $this->admin());
        $this->audit->log('refund.rejected', 'refunds', $refund, $refund->reference, ['status' => 'requested'], ['status' => 'rejected', 'reason' => $request->input('reason')]);

        return $this->ok(AdminPresenter::refund($refund->load(['order.event', 'order.user', 'decidedBy'])));
    }

    public function commissions(): JsonResponse
    {
        return $this->ok(CommissionRule::query()->with('updatedBy')->where('is_active', true)
            ->orderByRaw("case scope when 'platform' then 0 when 'category' then 1 else 2 end")->orderBy('scope_label')->get()
            ->map(fn ($r) => AdminPresenter::commission($r))->all());
    }

    public function createCommission(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'in:organizer,category'], 'scopeId' => ['required', 'string', 'max:40'], 'scopeLabel' => ['required', 'string', 'max:200'],
            'percent' => ['required', 'numeric', 'min:0', 'max:50'], 'fixedFee' => ['required', 'integer', 'min:0'],
        ]);
        $exists = $data['scope'] === 'category' ? DB::table('event_categories')->where('id', $data['scopeId'])->exists() : DB::table('users')->where('id', $data['scopeId'])->exists();
        if (! $exists) {
            throw ApiException::validation('Unknown scope.', ['scopeId' => ['The selected '.$data['scope'].' does not exist.']]);
        }
        $rule = DB::transaction(function () use ($data) {
            CommissionRule::query()->where('scope', $data['scope'])->where('scope_id', $data['scopeId'])->where('is_active', true)->update(['is_active' => false]);

            return CommissionRule::query()->create([
                'scope' => $data['scope'], 'scope_id' => $data['scopeId'], 'scope_label' => $data['scopeLabel'], 'percent' => $data['percent'],
                'fixed_fee' => $data['fixedFee'], 'currency' => 'NGN', 'effective_from' => now()->toDateString(), 'updated_by_admin_id' => $this->admin()->id,
            ]);
        });
        $this->audit->log('commission.created', 'finance', $rule, $rule->scope_label, null, $data);

        return $this->created(AdminPresenter::commission($rule->load('updatedBy')));
    }

    public function updateCommission(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['percent' => ['sometimes', 'numeric', 'min:0', 'max:50'], 'fixedFee' => ['sometimes', 'integer', 'min:0']]);
        $rule = CommissionRule::query()->where('is_active', true)->findOrFail($id);
        $before = ['percent' => (float) $rule->percent, 'fixedFee' => $rule->fixed_fee];
        // Rules are versioned, never edited in place: orders snapshot the rule they were sold under.
        $next = DB::transaction(function () use ($rule, $data) {
            $rule->forceFill(['is_active' => false])->save();

            return CommissionRule::query()->create([
                'scope' => $rule->scope, 'scope_id' => $rule->scope_id, 'scope_label' => $rule->scope_label,
                'percent' => $data['percent'] ?? $rule->percent, 'fixed_fee' => $data['fixedFee'] ?? $rule->fixed_fee,
                'currency' => $rule->currency, 'effective_from' => now()->toDateString(), 'updated_by_admin_id' => $this->admin()->id,
            ]);
        });
        $this->audit->log('commission.updated', 'finance', $next, $next->scope_label, $before, $data);

        return $this->ok(AdminPresenter::commission($next->load('updatedBy')));
    }

    public function gateways(MetricsService $metrics): JsonResponse
    {
        return $this->ok(Cache::remember('admin:gateway_health', 300, fn () => $metrics->gatewayHealth()));
    }
}
