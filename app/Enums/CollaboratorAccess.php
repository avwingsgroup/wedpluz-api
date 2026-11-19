<?php

namespace App\Enums;

enum CollaboratorAccess: string
{
    use EnumHelpers;

    case Full = 'full';
    case Partial = 'partial';
}
