<?php

namespace App\Enums;

enum MessageType: string
{
    use EnumHelpers;

    case Text = 'text';
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case File = 'file';
    case EventPromo = 'event_promo';
    case System = 'system';
}
