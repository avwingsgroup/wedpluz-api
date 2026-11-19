<?php

namespace App\Enums;

enum CollaboratorStatus: string
{
    use EnumHelpers;

    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Removed = 'removed';
}
