<?php

namespace App\Enums;

enum CommunityRole: string
{
    use EnumHelpers;

    case Owner = 'owner';
    case CoAdmin = 'co_admin';
    case Member = 'member';
    case None = 'none';
}
