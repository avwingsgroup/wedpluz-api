<?php

namespace App\Enums;

enum ReconciliationStatus: string
{
    use EnumHelpers;

    case Pending = 'pending';
    case Matched = 'matched';
    case Unmatched = 'unmatched';
    case Mismatch = 'mismatch';
}
