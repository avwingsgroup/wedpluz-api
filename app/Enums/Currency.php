<?php

namespace App\Enums;

enum Currency: string
{
    use EnumHelpers;

    case Ngn = 'NGN';
    case Usd = 'USD';
    case Gbp = 'GBP';
    case Eur = 'EUR';
}
