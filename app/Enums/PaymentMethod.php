<?php

namespace App\Enums;

enum PaymentMethod: string
{
    use EnumHelpers;

    case Paystack = 'paystack';
    case Stripe = 'stripe';
    case Paypal = 'paypal';
    case Monnify = 'monnify';
    case Flutterwave = 'flutterwave';
    case GooglePay = 'google_pay';
    case Wallet = 'wallet';
    case Card = 'card';
    case Free = 'free';
}
