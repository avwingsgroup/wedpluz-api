<?php

namespace App\Enums;

enum SettlementStatus: string
{
    use EnumHelpers;

    case Pending = 'pending';
    case Ready = 'ready';
    case OnHold = 'on_hold';
    case Paid = 'paid';
    case Failed = 'failed';
}
