<?php

namespace App\Enums;

enum IdentityStatus: string
{
    use EnumHelpers;

    case Unverified = 'unverified';
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';
}
