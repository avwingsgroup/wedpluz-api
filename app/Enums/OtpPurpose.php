<?php

namespace App\Enums;

enum OtpPurpose: string
{
    use EnumHelpers;

    case EmailVerification = 'email_verification';
    case PasswordReset = 'password_reset';
    case WalletSetup = 'wallet_setup';
    case BusinessEmail = 'business_email';
}
