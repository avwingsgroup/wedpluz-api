<?php

namespace App\Enums;

enum AdminStatus: string
{
    use EnumHelpers;

    case Active = 'active';
    case Suspended = 'suspended';
    case Invited = 'invited';
}
