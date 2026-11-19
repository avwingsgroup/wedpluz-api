<?php

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Integrations\Payments\PaymentManager;
use App\Integrations\Payments\PaymentVerification;
use App\Integrations\Support\IntegrationException;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Checkout\FulfillmentService;
use Illuminate\Support\Facades\Log;

/**
 * Server-side payment verification. Never trusts redirect parameters: the
 * provider is re-queried by reference and status, amount, currency and
 * reference are checked before anything is fulfilled.
 */
class PaymentService
{
    public function __construct(
        private readonly PaymentManager $payments,
        private readonly FulfillmentService $fulfillment,
    ) {}

    /** POST /orders/{id}/verify-payment */
    public function verifyOrder(Order $order, ?string $simulate = null): Order
    {
        if (in_array($order->status, [OrderStatus::Paid, OrderStatus::Refunded, OrderStatus::RefundProcessing, OrderStatus::Cancelled], true)) {
            return $order;
        }

        $payment = Payment::query()->where('payable_type', 'order')->where('payable_id', $order->id)->latest('created_at')->first();
        if ($payment === null) {
            throw ApiException::notFound('No payment was started for this order.');
        }
        if ($payment->status === PaymentStatus::Succeeded) {
            return $this->fulfillment->confirm($order, $payment);
        }
        if ($payment->status === PaymentStatus::Mismatch) {
            return $order;
        }

        if ($simulate !== null && $this->simulationAllowed()) {
            $verification = $simulate === 'success'
                ? new PaymentVerification(PaymentVerification::SUCCEEDED, $payment->amount, $payment->currency, 'sim_'.$payment->id)
                : new PaymentVerification(PaymentVerification::FAILED, null, null, null, 0, [], 'Simulated failure');
        } else {
            $verification = $this->verify($payment);
        }

        return $this->fulfillment->applyVerification($payment, $verification);
    }

    /** Query the provider for a payment; provider outages read as "still pending". */
    public function verify(Payment $payment): PaymentVerification
    {
        if (! $this->payments->has($payment->provider)) {
            return new PaymentVerification(PaymentVerification::PENDING);
        }

        try {
            return $this->payments->gateway($payment->provider)->verify($payment);
        } catch (IntegrationException $e) {
            Log::warning('payment.verify_failed', ['payment' => $payment->id, 'provider' => $payment->provider, 'error' => $e->getMessage()]);

            return new PaymentVerification(PaymentVerification::PENDING);
        }
    }

    private function simulationAllowed(): bool
    {
        return ! app()->isProduction() && (bool) config('wedpluz.payments.allow_simulation', false);
    }
}
