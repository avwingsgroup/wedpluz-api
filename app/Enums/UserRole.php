<?php

namespace App\Enums;

enum UserRole: string
{
    use EnumHelpers;

    case User = 'user';
    case Vendor = 'vendor';
    case Organizer = 'organizer';
    case Admin = 'admin';
}
