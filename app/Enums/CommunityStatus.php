<?php

namespace App\Enums;

enum CommunityStatus: string
{
    use EnumHelpers;

    case Active = 'active';
    case Archived = 'archived';
    case Suspended = 'suspended';
}
