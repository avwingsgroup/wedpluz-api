<?php

namespace App\Enums;

enum ManagerPermission: string
{
    use EnumHelpers;

    case ManageGuests = 'manage_guests';
    case ScanTickets = 'scan_tickets';
    case ManageTasks = 'manage_tasks';
    case ManageVendors = 'manage_vendors';
    case ManageAssets = 'manage_assets';
    case ViewRevenue = 'view_revenue';
    case EditEvent = 'edit_event';
    case ManageCommunity = 'manage_community';
}
