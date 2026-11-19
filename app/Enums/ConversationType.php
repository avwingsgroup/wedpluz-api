<?php

namespace App\Enums;

enum ConversationType: string
{
    use EnumHelpers;

    case Direct = 'direct';
    case Community = 'community';
    case Request = 'request';
}
