<?php

namespace App\Enums;

enum ReportStatus: string
{
    use EnumHelpers;

    case Open = 'open';
    case Reviewing = 'reviewing';
    case Actioned = 'actioned';
    case Dismissed = 'dismissed';
}
