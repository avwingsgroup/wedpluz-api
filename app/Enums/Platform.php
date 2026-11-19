<?php

namespace App\Enums;

enum Platform: string
{
    use EnumHelpers;

    case Web = 'web';
    case Android = 'android';
    case Ios = 'ios';
    case Admin = 'admin';
}
