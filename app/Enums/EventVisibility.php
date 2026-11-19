<?php

namespace App\Enums;

enum EventVisibility: string
{
    use EnumHelpers;

    case Public = 'public';
    case Private = 'private';
}
