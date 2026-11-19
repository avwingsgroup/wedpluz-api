<?php

namespace App\Enums;

enum VendorStatus: string
{
    use EnumHelpers;

    case Draft = 'draft';
    case PendingVerification = 'pending_verification';
    case Active = 'active';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
}
