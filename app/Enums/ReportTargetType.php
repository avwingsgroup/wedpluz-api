<?php

namespace App\Enums;

enum ReportTargetType: string
{
    use EnumHelpers;

    case User = 'user';
    case Message = 'message';
    case Community = 'community';
    case Event = 'event';
    case Vendor = 'vendor';
    case Work = 'work';
    case Status = 'status';
    case Review = 'review';
}
