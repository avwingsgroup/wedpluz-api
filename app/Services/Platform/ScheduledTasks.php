<?php

namespace App\Services\Platform;

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Api\V1\Admin\AdminDashboardController;
use App\Integrations\Payments\PaymentVerification;
use App\Models\AccountDeletionRequest;
use App\Models\Event;
use App\Models\NotificationCampaign;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StatusPost;
use App\Models\Upload;
use App\Models\User;
use App\Services\Admin\MetricsService;
use App\Services\Checkout\FulfillmentService;
use App\Services\Events\EventPresenter;
use App\Services\Events\EventService;
use App\Services\Finance\SettlementService;
use App\Services\Media\UploadService;
use App\Services\Notifications\CampaignService;
use App\Services\Notifications\NotificationService;
use App\Services\Payments\PaymentService;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\Users\AccountDeletionService;
use App\Support\Platform;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/** Recurring background work, invoked from routes/console.php on the scheduler. */
class ScheduledTasks
{
    /** Every minute: expire unpaid orders whose hold ended, after one last provider check. */
    public function expireOrders(): int
    {
        $count = 0;
        Order::query()->whereIn('status', [OrderStatus::AwaitingPayment->value, OrderStatus::Pending->value])
            ->where('expires_at', '<', now())->orderBy('expires_at')->limit(500)->get()
            ->each(function (Order $order) use (&$count) {
                $payment = Payment::query()->where('payable_type', 'order')->where('payable_id', $order->id)->latest()->first();
                if ($payment && $payment->provider !== 'internal') {
                    $verification = app(PaymentService::class)->verify($payment);
                    if ($verification->status === PaymentVerification::SUCCEEDED) {
                        app(FulfillmentService::class)->applyVerification($payment, $verification);

                        return;
                    }
                }
                DB::transaction(function () use ($order, $payment, &$count) {
                    $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
                    if (! in_array($locked->status, [OrderStatus::AwaitingPayment, OrderStatus::Pending], true)) {
                        return;
                    }
                    $locked->forceFill(['status' => OrderStatus::Expired])->save();
                    $payment?->forceFill(['status' => 'failed', 'failure_reason' => 'Checkout expired'])->save();
                    app(FulfillmentService::class)->releaseReservations($locked);
                    $count++;
                });
            });

        return $count;
    }

    /** Every 15 minutes: mark live events completed after their last schedule ends (starts the settlement clock). */
    public function completeEvents(): int
    {
        $events = Event::query()->where('status', EventStatus::Live->value)->whereNotNull('ends_at')->where('ends_at', '<', now())->limit(200)->get();
        foreach ($events as $event) {
            app(EventService::class)->complete($event);
        }

        return $events->count();
    }

    public function promoteSettlements(): int
    {
        return app(SettlementService::class)->promoteEligible();
    }

    public function renewSubscriptions(): void
    {
        app(SubscriptionService::class)->processRenewals();
    }

    public function dispatchScheduledCampaigns(): int
    {
        $due = NotificationCampaign::query()->where('status', 'scheduled')->where('scheduled_at', '<=', now())->get();
        foreach ($due as $campaign) {
            app(CampaignService::class)->start($campaign);
        }

        return $due->count();
    }

    /** Every 15 minutes: reminders 24 hours and 2 hours before start to ticket holders (deduplicated). */
    public function sendEventReminders(): int
    {
        $sent = 0;
        foreach (['24h' => [23.75, 24.25, 'tomorrow'], '2h' => [1.75, 2.25, 'in 2 hours']] as $window => [$from, $to, $when]) {
            $events = Event::query()->where('status', 'live')
                ->whereBetween('starts_at', [now()->addMinutes((int) ($from * 60)), now()->addMinutes((int) ($to * 60))])->get();
            foreach ($events as $event) {
                $holderIds = DB::table('tickets')->where('event_id', $event->id)->where('status', 'valid')->whereNotNull('holder_user_id')->distinct()->pluck('holder_user_id');
                foreach (User::query()->whereIn('id', $holderIds)->get() as $user) {
                    app(NotificationService::class)->notify($user, 'event_reminder', [
                        'tokens' => ['event' => $event->title, 'when' => $when],
                        'data' => ['eventId' => $event->id, 'eventSlug' => $event->slug],
                        'dedupe' => 'reminder:'.$window.':'.$event->id.':'.$user->id,
                        'email' => ['eventTitle' => $event->title, 'when' => $when, 'eventDate' => $event->starts_at->setTimezone(Platform::timezone())->format('D j M, g:i A')],
                    ]);
                    $sent++;
                }
            }
        }

        return $sent;
    }

    /** Daily: anonymise accounts whose 30-day grace period ended. */
    public function processAccountDeletions(): int
    {
        $due = AccountDeletionRequest::query()->where('status', 'pending')->where('scheduled_for', '<=', now())->get();
        foreach ($due as $request) {
            app(AccountDeletionService::class)->complete($request);
        }

        return $due->count();
    }

    /** Hourly: archive event communities 7 days after cancellation. */
    public function archiveCommunities(): int
    {
        return DB::table('communities')->where('status', 'active')->whereNotNull('archive_at')->where('archive_at', '<=', now())
            ->update(['status' => 'archived', 'updated_at' => now()]);
    }

    /**
     * Daily reconciliation: re-query the provider for yesterday's successful
     * payments still pending reconciliation; matching records become `matched`,
     * anything else `mismatch` for an admin to resolve.
     */
    public function reconcilePayments(): array
    {
        $result = ['matched' => 0, 'mismatch' => 0];
        Payment::query()->where('status', 'succeeded')->where('reconciliation_status', 'pending')
            ->whereNotIn('provider', ['wallet', 'internal'])->where('verified_at', '<', now()->subHours(2))
            ->orderBy('verified_at')->limit(1000)->get()
            ->each(function (Payment $payment) use (&$result) {
                $v = app(PaymentService::class)->verify($payment);
                if ($v->status === PaymentVerification::PENDING) {
                    return; // provider unreachable; try again tomorrow
                }
                $ok = $v->status === PaymentVerification::SUCCEEDED && ($v->amount === null || $v->amount === $payment->amount);
                $payment->forceFill(['reconciliation_status' => $ok ? 'matched' : 'mismatch', 'settled_at' => $ok ? now() : null])->save();
                $result[$ok ? 'matched' : 'mismatch']++;
            });
        if ($result['mismatch'] > 0) {
            app(AlertService::class)->raise('critical', 'Reconciliation mismatches', $result['mismatch'].' payments did not match provider records.', '/finance/transactions?reconciliationStatus=mismatch', 'recon:'.now()->toDateString());
        }

        return $result;
    }

    /** Daily retention (business-rules.md, "Data retention"). */
    public function purge(): array
    {
        $uploads = app(UploadService::class);
        $stats = ['uploads' => 0, 'statusMedia' => 0, 'identityMedia' => 0, 'exports' => 0];

        Upload::query()->whereNull('attached_at')->where('expires_at', '<', now())->limit(1000)->get()
            ->each(function (Upload $u) use ($uploads, &$stats) {
                $uploads->delete($u);
                $stats['uploads']++;
            });

        StatusPost::withTrashed()->where('expires_at', '<', now()->subDays(7))->whereNull('media_purged_at')->limit(1000)->get()
            ->each(function (StatusPost $s) use ($uploads, &$stats) {
                if ($s->upload_id && ($u = Upload::query()->find($s->upload_id))) {
                    $uploads->delete($u);
                }
                $s->forceFill(['media_purged_at' => now()])->saveQuietly();
                $stats['statusMedia']++;
            });

        DB::table('identity_verifications')->whereIn('status', ['verified', 'failed'])->whereNull('media_purged_at')
            ->where('reviewed_at', '<', now()->subDays(90))->orderBy('reviewed_at')->limit(500)->get()
            ->each(function ($v) use ($uploads, &$stats) {
                if ($v->selfie_upload_id && ($u = Upload::query()->find($v->selfie_upload_id))) {
                    $uploads->delete($u);
                }
                DB::table('identity_verifications')->where('id', $v->id)->update(['media_purged_at' => now(), 'selfie_upload_id' => null]);
                $stats['identityMedia']++;
            });

        DB::table('export_jobs')->where('status', 'ready')->where('expires_at', '<', now())->get()
            ->each(function ($e) use (&$stats) {
                if ($e->file_path) {
                    Storage::disk('private')->delete($e->file_path);
                }
                DB::table('export_jobs')->where('id', $e->id)->update(['status' => 'failed', 'file_path' => null, 'error' => 'Expired']);
                $stats['exports']++;
            });

        DB::table('idempotency_keys')->where('created_at', '<', now()->subDays(2))->delete();
        DB::table('otp_codes')->where('created_at', '<', now()->subDays(7))->delete();
        DB::table('integration_logs')->where('created_at', '<', now()->subDays(90))->delete();
        DB::table('checkout_quotes')->whereNull('consumed_at')->where('expires_at', '<', now()->subDays(7))->delete();
        DB::table('refresh_tokens')->where('expires_at', '<', now()->subDays(7))->delete();
        DB::table('personal_access_tokens')->where('expires_at', '<', now()->subDays(1))->delete();

        Log::info('retention.purge', $stats);

        return $stats;
    }

    /** Every 10 minutes: keep the default 30-day dashboard and gateway health warm. */
    public function warmMetrics(): void
    {
        $metrics = app(MetricsService::class);
        [$from, $to] = $metrics->period(null, null);
        Cache::put('admin:dashboard:'.$from->timestamp.':'.$to->timestamp,
            app(AdminDashboardController::class)->aggregate($metrics, app(EventPresenter::class), $from, $to), 600);
        Cache::put('admin:gateway_health', $metrics->gatewayHealth(), 300);
    }
}
