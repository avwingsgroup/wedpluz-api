<?php

namespace App\Enums;

enum PaymentProvider: string
{
    use EnumHelpers;

    case Paystack = 'paystack';
    case Stripe = 'stripe';
    case Paypal = 'paypal';
    case Monnify = 'monnify';
    case Flutterwave = 'flutterwave';
    case Wallet = 'wallet';
    case None = 'none';
}
