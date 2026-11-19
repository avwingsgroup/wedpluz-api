<?php

namespace App\Enums;

enum NotificationChannel: string
{
    use EnumHelpers;

    case Push = 'push';
    case Email = 'email';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
    case InApp = 'in_app';
}
