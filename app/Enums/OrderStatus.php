<?php

namespace App\Enums;

enum OrderStatus: string
{
    use EnumHelpers;

    case Pending = 'pending';
    case AwaitingPayment = 'awaiting_payment';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case RefundProcessing = 'refund_processing';
    case Refunded = 'refunded';
    case Expired = 'expired';
}
