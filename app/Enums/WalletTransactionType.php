<?php

namespace App\Enums;

enum WalletTransactionType: string
{
    use EnumHelpers;

    case TopUp = 'top_up';
    case Withdrawal = 'withdrawal';
    case TicketPayment = 'ticket_payment';
    case Refund = 'refund';
    case Payout = 'payout';
    case Subscription = 'subscription';
    case PromotionEarning = 'promotion_earning';
    case Reversal = 'reversal';
}
