<?php

namespace App\Enums;

enum AttendanceMode: string
{
    use EnumHelpers;

    case Online = 'online';
    case Physical = 'physical';
    case Hybrid = 'hybrid';
}
