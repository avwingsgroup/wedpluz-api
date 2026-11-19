<?php

namespace App\Enums;

enum PlanTier: string
{
    use EnumHelpers;

    case Free = 'free';
    case Basic = 'basic';
    case Premium = 'premium';
    case PremiumPlus = 'premium_plus';
}
