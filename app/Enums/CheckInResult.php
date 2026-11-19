<?php

namespace App\Enums;

enum CheckInResult: string
{
    use EnumHelpers;

    case Ok = 'ok';
    case NotFound = 'not_found';
    case WrongEvent = 'wrong_event';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case WrongDay = 'wrong_day';
    case AlreadyCheckedIn = 'already_checked_in';
}
