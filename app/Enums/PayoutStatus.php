<?php

namespace App\Enums;

enum PayoutStatus: string
{
    use EnumHelpers;

    case Requested = 'requested';
    case Approved = 'approved';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Rejected = 'rejected';
}
