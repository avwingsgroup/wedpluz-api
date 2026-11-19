<?php

namespace App\Enums;

enum TicketStatus: string
{
    use EnumHelpers;

    case Valid = 'valid';
    case CheckedIn = 'checked_in';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Transferred = 'transferred';
}
