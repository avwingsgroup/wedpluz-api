<?php

namespace App\Enums;

enum RefundStatus: string
{
    use EnumHelpers;

    case Requested = 'requested';
    case Approved = 'approved';
    case Processing = 'processing';
    case Completed = 'completed';
    case Rejected = 'rejected';
}
