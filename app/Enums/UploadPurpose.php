<?php

namespace App\Enums;

enum UploadPurpose: string
{
    use EnumHelpers;

    case Avatar = 'avatar';
    case Cover = 'cover';
    case EventCover = 'event_cover';
    case EventGallery = 'event_gallery';
    case VendorWork = 'vendor_work';
    case MessageAttachment = 'message_attachment';
    case Status = 'status';
    case IdentitySelfie = 'identity_selfie';
    case TaskAttachment = 'task_attachment';
    case PlannerReference = 'planner_reference';
    case Document = 'document';
}
