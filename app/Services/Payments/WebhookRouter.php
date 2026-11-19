<?php

namespace App\Services\Payments;

use App\Integrations\Identity\IdentityResult;
use App\Integrations\Payments\PaymentManager;
use App\Integrations\Payments\PaymentVerification;
use App\Integrations\Payments\WebhookPayload;
use App\Models\Dispute;
use App\Models\IdentityVerification;
use App\Models\NotificationDelivery;
use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Services\Checkout\FulfillmentService;
use App\Services\Checkout\RefundService;
use App\Services\Finance\PayoutService;
use App\Services\Platform\AlertService;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\Users\IdentityVerificationService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Routes stored webhook events to idempotent domain handlers: orders, wallet
 * top-ups, subscriptions, refunds, transfers (withdrawals and payouts),
 * disputes, identity results and message delivery reports.
 */
class WebhookRouter
{
    public function __construct(
        private readonly PaymentManager $payments,
        private readonly PaymentService $paymentService,
        private readonly FulfillmentService $fulfillment,
    ) {}

    public function route(WebhookEvent $event): void
    {
        match ($event->provider) {
            'paystack', 'flutterwave', 'monnify', 'stripe', 'paypal' => $this->payment($event),
            'prembly' => $this->identity($event),
            'zeptomail' => $this->email($event),
            'termii', 'twilio', 'whatsapp' => $this->delivery($event),
            default => Log::info('webhook.unhandled_provider', ['provider' => $event->provider]),
        };
    }

    private function payment(WebhookEvent $event): void
    {
        $payload = WebhookService::payload($event);
        if ($payload === null) {
            return;
        }

        match ($payload->type) {
            WebhookPayload::PAYMENT_SUCCEEDED, WebhookPayload::PAYMENT_FAILED => $this->paymentResult($event->provider, $payload),
            WebhookPayload::REFUND_COMPLETED => app(RefundService::class)->providerResult($event->provider, (string) $payload->reference, true, $payload->data),
            WebhookPayload::REFUND_FAILED => app(RefundService::class)->providerResult($event->provider, (string) $payload->reference, false, $payload->data),
            WebhookPayload::TRANSFER_SUCCEEDED => $this->transfer((string) $payload->reference, 'successful', $payload->data),
            WebhookPayload::TRANSFER_FAILED => $this->transfer((string) $payload->reference, 'failed', $payload->data),
            WebhookPayload::TRANSFER_REVERSED => $this->transfer((string) $payload->reference, 'reversed', $payload->data),
            WebhookPayload::DISPUTE_CREATED => $this->dispute($event->provider, $payload),
            default => null,
        };
    }

    private function paymentResult(string $provider, WebhookPayload $payload): void
    {
        $payment = $this->findPayment($provider, $payload);
        if ($payment === null) {
            Log::notice('webhook.payment_not_found', ['provider' => $provider, 'reference' => $payload->reference]);

            return; // acknowledged; providers must not retry for unknown references
        }
        if (isset($payload->data['capture_id']) || isset($payload->data['payment_intent'])) {
            $payment->forceFill(['raw_payload' => array_merge($payment->raw_payload ?? [], array_filter([
                'capture_id' => $payload->data['capture_id'] ?? null,
                'payment_intent' => $payload->data['payment_intent'] ?? null,
            ]))])->save();
        }

        // Webhooks are authoritative, but we still re-query the provider before fulfilling.
        $verification = $this->paymentService->verify($payment);
        if ($verification->status === PaymentVerification::PENDING && $payload->type === WebhookPayload::PAYMENT_FAILED) {
            $verification = new PaymentVerification(PaymentVerification::FAILED, null, null, null, 0, [], 'Payment failed at provider.');
        }
        if ($verification->status === PaymentVerification::PENDING) {
            throw new \RuntimeException('Provider still reports the payment as pending; retrying.');
        }

        match ($payment->payable_type) {
            'order' => $this->fulfillment->applyVerification($payment, $verification),
            'wallet_transaction' => app(WalletService::class)->applyTopUpVerification($payment, $verification),
            'subscription_payment' => app(SubscriptionService::class)->applyVerification($payment, $verification),
            default => null,
        };
    }

    private function findPayment(string $provider, WebhookPayload $payload): ?Payment
    {
        $query = Payment::query()->where('provider', $provider);
        if ($payload->reference) {
            $found = (clone $query)->where('provider_reference', $payload->reference)->first();
            if ($found) {
                return $found;
            }
        }
        $txn = $payload->data['id'] ?? null;

        return $txn ? (clone $query)->where('provider_transaction_id', (string) $txn)->first() : null;
    }

    private function transfer(string $reference, string $status, array $data): void
    {
        if (str_starts_with($reference, 'WD-')) {
            app(WalletService::class)->withdrawalResult($reference, $status, (string) ($data['reason'] ?? $data['complete_message'] ?? ''));
        } elseif (str_starts_with($reference, 'PO-')) {
            app(PayoutService::class)->transferResult($reference, $status, (string) ($data['reason'] ?? $data['complete_message'] ?? ''));
        } else {
            Log::notice('webhook.transfer_unknown_reference', ['reference' => $reference]);
        }
    }

    private function dispute(string $provider, WebhookPayload $payload): void
    {
        $payment = $payload->reference
            ? Payment::query()->where('provider', $provider)->where('provider_reference', $payload->reference)->first()
            : (isset($payload->data['payment_intent']) ? Payment::query()->where('provider', $provider)->whereRaw("raw_payload->>'payment_intent' = ?", [$payload->data['payment_intent']])->first() : null);
        if ($payment === null || $payment->payable_type !== 'order') {
            app(AlertService::class)->raise('warning', 'Unmatched chargeback', 'A '.$provider.' dispute could not be matched to an order.', '/finance/transactions', 'dispute-unmatched:'.($payload->data['dispute_id'] ?? $payload->eventId));

            return;
        }

        DB::transaction(function () use ($payment, $payload) {
            Dispute::query()->firstOrCreate(
                ['provider_dispute_id' => (string) ($payload->data['dispute_id'] ?? $payload->eventId)],
                ['order_id' => $payment->payable_id, 'raised_by_user_id' => $payment->user_id, 'type' => 'chargeback', 'status' => 'open',
                    'description' => 'Chargeback opened at '.$payment->provider.(isset($payload->data['reason']) ? ': '.$payload->data['reason'] : '.')],
            );
            $eventId = DB::table('orders')->where('id', $payment->payable_id)->value('event_id');
            DB::table('settlements')->where('event_id', $eventId)->whereIn('status', ['pending', 'ready'])
                ->update(['status' => 'on_hold', 'hold_reason' => 'Open chargeback on an order', 'updated_at' => now()]);
        });
        app(AlertService::class)->raise('critical', 'Chargeback received', 'A chargeback was opened on a '.$payment->provider.' payment ('.$payment->provider_reference.').', '/disputes', 'dispute:'.$payment->id);
    }

    private function identity(WebhookEvent $event): void
    {
        $data = $event->payload;
        $reference = (string) ($data['reference'] ?? $data['verification']['reference'] ?? $data['data']['reference'] ?? '');
        $verification = IdentityVerification::query()->where('provider_reference', $reference)->orWhere('id', $reference)->first();
        if ($verification === null) {
            return;
        }
        $status = strtoupper((string) ($data['verification']['status'] ?? $data['status'] ?? ''));
        $result = match (true) {
            in_array($status, ['VERIFIED', 'SUCCESS', 'TRUE', '1'], true) => new IdentityResult('verified', $reference, isset($data['face_data']['confidence']) ? (float) $data['face_data']['confidence'] : null),
            in_array($status, ['NOT-VERIFIED', 'FAILED', 'FALSE', '0'], true) => new IdentityResult('failed', $reference, null, 'We could not verify your identity with the details provided.'),
            default => new IdentityResult('pending', $reference),
        };
        app(IdentityVerificationService::class)->apply($verification, $result);
    }

    private function email(WebhookEvent $event): void
    {
        $data = $event->payload;
        $type = strtolower((string) $event->type);
        foreach ((array) ($data['event_message'] ?? [$data]) as $message) {
            $emails = collect($message['email_info']['to'] ?? [])->pluck('email_address.address')->filter()->all();
            if (str_contains($type, 'bounce') || str_contains($type, 'complaint') || str_contains($type, 'spam')) {
                foreach ($emails as $email) {
                    DB::table('email_suppressions')->updateOrInsert(['email' => mb_strtolower($email)], ['reason' => str_contains($type, 'bounce') ? 'bounce' : 'complaint', 'updated_at' => now(), 'created_at' => now()]);
                }
            }
            $requestId = $message['request_id'] ?? null;
            if ($requestId) {
                $status = match (true) {
                    str_contains($type, 'open') => 'opened',
                    str_contains($type, 'click') => 'clicked',
                    str_contains($type, 'bounce'), str_contains($type, 'complaint') => 'failed',
                    default => 'delivered',
                };
                $this->updateDelivery('zeptomail', (string) $requestId, $status);
            }
        }
    }

    private function delivery(WebhookEvent $event): void
    {
        $data = $event->payload;
        [$messageId, $status] = match ($event->provider) {
            'termii' => [$data['message_id'] ?? null, strtolower((string) ($data['status'] ?? ''))],
            'twilio' => [$data['MessageSid'] ?? null, strtolower((string) ($data['MessageStatus'] ?? ''))],
            'whatsapp' => [$data['id'] ?? null, strtolower((string) ($data['status'] ?? ''))],
        };
        if (! $messageId) {
            return;
        }
        $normalised = match (true) {
            in_array($status, ['delivered', 'delivered to handset'], true) => 'delivered',
            $status === 'read' => 'read',
            in_array($status, ['failed', 'undelivered', 'rejected', 'expired', 'dnd active on phone number'], true) => 'failed',
            default => null,
        };
        if ($normalised) {
            $this->updateDelivery($event->provider, (string) $messageId, $normalised);
        }
    }

    private function updateDelivery(string $provider, string $messageId, string $status): void
    {
        $delivery = NotificationDelivery::query()->where('provider_message_id', $messageId)->first();
        if ($delivery === null) {
            return;
        }
        $changes = ['status' => $status];
        if ($status === 'delivered' && ! $delivery->delivered_at) {
            $changes['delivered_at'] = now();
        }
        if ($status === 'opened' || $status === 'read') {
            $changes['opened_at'] ??= now();
            $changes['delivered_at'] = $delivery->delivered_at ?? now();
        }
        if ($status === 'clicked') {
            $changes['clicked_at'] = now();
        }
        $delivery->forceFill($changes)->save();

        if ($delivery->campaign_id && in_array($status, ['delivered', 'opened', 'read', 'clicked', 'failed'], true)) {
            $key = match ($status) {
                'read' => 'opened', default => $status
            };
            DB::table('notification_campaigns')->where('id', $delivery->campaign_id)->update([
                'stats' => DB::raw("jsonb_set(stats, '{".$key."}', to_jsonb(coalesce((stats->>'".$key."')::numeric, 0) + 1))"),
            ]);
        }
    }
}
