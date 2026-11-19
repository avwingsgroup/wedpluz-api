<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    use EnumHelpers;

    case Active = 'active';
    case Trialing = 'trialing';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';
}
