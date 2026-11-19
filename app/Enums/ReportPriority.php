<?php

namespace App\Enums;

enum ReportPriority: string
{
    use EnumHelpers;

    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
