<?php

namespace App\Enums;

/**
 * The admin permission catalogue (authentication-and-permissions.md §6.1).
 * Every /admin/* route declares exactly one of these.
 */
enum AdminPermission: string
{
    use EnumHelpers;

    case DashboardView = 'dashboard.view';
    case UsersView = 'users.view';
    case UsersManage = 'users.manage';
    case UsersSuspend = 'users.suspend';
    case EventsView = 'events.view';
    case EventsModerate = 'events.moderate';
    case EventsFeature = 'events.feature';
    case CategoriesManage = 'categories.manage';
    case OrganizersView = 'organizers.view';
    case OrganizersVerify = 'organizers.verify';
    case OrganizersManage = 'organizers.manage';
    case BookingsView = 'bookings.view';
    case BookingsManage = 'bookings.manage';
    case RefundsView = 'refunds.view';
    case RefundsApprove = 'refunds.approve';
    case FinanceView = 'finance.view';
    case FinanceReconcile = 'finance.reconcile';
    case FinanceSettlements = 'finance.settlements';
    case FinancePayoutsApprove = 'finance.payouts.approve';
    case FinanceCommissionManage = 'finance.commission.manage';
    case ModerationView = 'moderation.view';
    case ModerationAct = 'moderation.act';
    case NotificationsView = 'notifications.view';
    case NotificationsSend = 'notifications.send';
    case SettingsView = 'settings.view';
    case SettingsManage = 'settings.manage';
    case RolesManage = 'roles.manage';
    case AuditView = 'audit.view';
    case ReportsView = 'reports.view';
    case ReportsExport = 'reports.export';
    case SupportView = 'support.view';
    case SupportManage = 'support.manage';

    /** @return array{module:string,label:string,description:string,isSensitive:bool} */
    public function meta(): array
    {
        $m = match ($this) {
            self::DashboardView => ['Dashboard', 'View dashboard', 'Executive dashboard', false],
            self::UsersView => ['Users', 'View users', 'List and view users, activity, bookings, reports, deletion requests', false],
            self::UsersManage => ['Users', 'Manage users', 'Change platform roles, decide deletion requests', true],
            self::UsersSuspend => ['Users', 'Suspend users', 'Suspend and reactivate users', true],
            self::EventsView => ['Events', 'View events', 'List and view events and categories', false],
            self::EventsModerate => ['Events', 'Moderate events', 'Approve, reject, suspend, restore events', false],
            self::EventsFeature => ['Events', 'Feature events', 'Feature and unfeature events', false],
            self::CategoriesManage => ['Events', 'Manage categories', 'Create, update, delete categories', false],
            self::OrganizersView => ['Organizers', 'View organizers', 'List and view organisers and vendors', false],
            self::OrganizersVerify => ['Organizers', 'Verify organizers', 'Approve or reject verification', false],
            self::OrganizersManage => ['Organizers', 'Manage organizers', 'Suspend and reactivate organisers and vendors', true],
            self::BookingsView => ['Bookings', 'View bookings', 'Bookings, disputes, check-ins, inventory', false],
            self::BookingsManage => ['Bookings', 'Manage bookings', 'Resend tickets, update disputes', false],
            self::RefundsView => ['Refunds', 'View refunds', 'Refunds list', false],
            self::RefundsApprove => ['Refunds', 'Approve refunds', 'Issue refunds, approve or reject refund requests', true],
            self::FinanceView => ['Finance', 'View finance', 'Summary, transactions, payouts list, commissions, gateways', false],
            self::FinanceReconcile => ['Finance', 'Reconcile', 'Mark transactions matched or mismatched', true],
            self::FinanceSettlements => ['Finance', 'Settlements', 'Settlements list, hold and release, create payout', true],
            self::FinancePayoutsApprove => ['Finance', 'Approve payouts', 'Approve or reject payouts (fresh 2FA)', true],
            self::FinanceCommissionManage => ['Finance', 'Manage commissions', 'Commission rules and organiser overrides', true],
            self::ModerationView => ['Moderation', 'View moderation', 'Reports and communities', false],
            self::ModerationAct => ['Moderation', 'Act on reports', 'Act on reports, assign, suspend, restore or archive communities', false],
            self::NotificationsView => ['Notifications', 'View notifications', 'Campaigns and templates', false],
            self::NotificationsSend => ['Notifications', 'Send notifications', 'Create, send, cancel campaigns, test sends, edit templates', true],
            self::SettingsView => ['Settings', 'View settings', 'Platform settings, banners, CMS pages', false],
            self::SettingsManage => ['Settings', 'Manage settings', 'Update settings, banners, CMS pages', true],
            self::RolesManage => ['Access', 'Manage roles', 'Permissions catalogue, roles, administrators', true],
            self::AuditView => ['Access', 'View audit logs', 'Audit and security logs', false],
            self::ReportsView => ['Reports', 'View reports', 'Report definitions and runs', false],
            self::ReportsExport => ['Reports', 'Export reports', 'Exports (may contain personal data)', true],
            self::SupportView => ['Support', 'View support', 'Support tickets', false],
            self::SupportManage => ['Support', 'Manage support', 'Assign, update, reply', false],
        };

        return ['module' => $m[0], 'label' => $m[1], 'description' => $m[2], 'isSensitive' => $m[3]];
    }

    /** Default roles from §6.2. @return array<string, array{name:string, description:string, permissions:list<string>}> */
    public static function defaultRoles(): array
    {
        $all = self::values();
        $events = ['events.view', 'events.moderate', 'events.feature'];

        return [
            'super-admin' => ['name' => 'Super Admin', 'description' => 'Full access to every module.', 'permissions' => $all],
            'operations' => ['name' => 'Operations', 'description' => 'Day-to-day platform operations.', 'permissions' => [
                'dashboard.view', 'users.view', 'users.suspend', ...$events, 'categories.manage', 'organizers.view',
                'organizers.verify', 'bookings.view', 'bookings.manage', 'moderation.view', 'support.view',
            ]],
            'finance' => ['name' => 'Finance', 'description' => 'Refunds, reconciliation, settlements and commissions.', 'permissions' => [
                'dashboard.view', 'bookings.view', 'refunds.view', 'refunds.approve', 'finance.view', 'finance.reconcile',
                'finance.settlements', 'finance.commission.manage', 'reports.view', 'reports.export',
            ]],
            'finance-approver' => ['name' => 'Finance Approver', 'description' => 'Approves payouts (four-eyes control).', 'permissions' => [
                'finance.view', 'finance.payouts.approve', 'audit.view',
            ]],
            'moderator' => ['name' => 'Moderator', 'description' => 'Content moderation.', 'permissions' => [
                'dashboard.view', 'users.view', 'users.suspend', 'events.view', 'events.moderate', 'moderation.view', 'moderation.act',
            ]],
            'support' => ['name' => 'Support', 'description' => 'Customer support.', 'permissions' => [
                'dashboard.view', 'users.view', 'bookings.view', 'bookings.manage', 'refunds.view', 'support.view', 'support.manage',
            ]],
            'marketing' => ['name' => 'Marketing', 'description' => 'Campaigns, banners and featured events.', 'permissions' => [
                'dashboard.view', 'events.view', 'events.feature', 'notifications.view', 'notifications.send', 'settings.view', 'reports.view',
            ]],
        ];
    }
}
