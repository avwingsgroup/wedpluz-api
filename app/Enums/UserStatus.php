<?php

namespace App\Enums;

enum UserStatus: string
{
    use EnumHelpers;

    case Active = 'active';
    case Suspended = 'suspended';
    case Deactivated = 'deactivated';
    case PendingDeletion = 'pending_deletion';
}
