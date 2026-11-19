<?php

namespace App\Enums;

enum PaymentStatus: string
{
    use EnumHelpers;

    case Initialized = 'initialized';
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Mismatch = 'mismatch';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
}
