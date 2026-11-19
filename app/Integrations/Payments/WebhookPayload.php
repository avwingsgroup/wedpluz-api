<?php

namespace App\Integrations\Payments;

/** A verified, normalised provider webhook. */
final class WebhookPayload
{
    public const PAYMENT_SUCCEEDED = 'payment.succeeded';

    public const PAYMENT_FAILED = 'payment.failed';

    public const REFUND_COMPLETED = 'refund.completed';

    public const REFUND_FAILED = 'refund.failed';

    public const TRANSFER_SUCCEEDED = 'transfer.succeeded';

    public const TRANSFER_FAILED = 'transfer.failed';

    public const TRANSFER_REVERSED = 'transfer.reversed';

    public const DISPUTE_CREATED = 'dispute.created';

    public function __construct(
        public readonly string $eventId,
        public readonly string $type,
        /** Our reference (order/top-up/subscription/refund/transfer reference) */
        public readonly ?string $reference,
        public readonly string $providerType,
        public readonly array $data = [],
    ) {}
}
