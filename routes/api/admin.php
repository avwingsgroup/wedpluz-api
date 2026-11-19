<?php

/*
| Admin console API. Admin token only (audience:admin); every route declares
| exactly one permission (deny by default). Every successful non-GET call is
| audit-logged (admin.audit).
*/

use App\Http\Controllers\Api\V1\Admin\AdminAccessController as Access;
use App\Http\Controllers\Api\V1\Admin\AdminAuthController as Auth;
use App\Http\Controllers\Api\V1\Admin\AdminBookingsController as Bookings;
use App\Http\Controllers\Api\V1\Admin\AdminDashboardController as Dashboard;
use App\Http\Controllers\Api\V1\Admin\AdminEventsController as Events;
use App\Http\Controllers\Api\V1\Admin\AdminFinanceController as Finance;
use App\Http\Controllers\Api\V1\Admin\AdminModerationController as Moderation;
use App\Http\Controllers\Api\V1\Admin\AdminNotificationsController as Notifications;
use App\Http\Controllers\Api\V1\Admin\AdminOrganizersController as Organizers;
use App\Http\Controllers\Api\V1\Admin\AdminPlatformController as Platform;
use App\Http\Controllers\Api\V1\Admin\AdminReportsController as Reports;
use App\Http\Controllers\Api\V1\Admin\AdminSupportController as Support;
use App\Http\Controllers\Api\V1\Admin\AdminUsersController as Users;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->group(function () {
    // Public admin auth (rate limited).
    Route::post('auth/login', [Auth::class, 'login'])->middleware('throttle:login')->name('auth.login');
    Route::post('auth/invitations/accept', [Auth::class, 'acceptInvitation'])->middleware('throttle:login')->name('auth.invitations.accept');
    Route::post('auth/invitations/confirm', [Auth::class, 'confirmInvitation'])->middleware('throttle:login')->name('auth.invitations.confirm');
    // Signed export download (signature checked in the controller).
    Route::get('exports/{id}/download', [Reports::class, 'download'])->name('exports.download');

    Route::middleware(['auth:sanctum', 'audience:admin', 'throttle:admin', 'admin.audit'])->group(function () {
        Route::get('me', [Auth::class, 'me'])->name('me');
        Route::post('auth/logout', [Auth::class, 'logout'])->name('auth.logout');

        $p = fn (string $permission) => 'permission:'.$permission;

        Route::get('dashboard', Dashboard::class)->middleware($p('dashboard.view'))->name('dashboard');

        // Users
        Route::get('users', [Users::class, 'index'])->middleware($p('users.view'))->name('users.index');
        Route::get('users/{id}', [Users::class, 'show'])->middleware($p('users.view'))->name('users.show');
        Route::get('users/{id}/activity', [Users::class, 'activity'])->middleware($p('users.view'))->name('users.activity');
        Route::get('users/{id}/tickets', [Users::class, 'tickets'])->middleware($p('users.view'))->name('users.tickets');
        Route::get('users/{id}/reports', [Users::class, 'reports'])->middleware($p('users.view'))->name('users.reports');
        Route::post('users/{id}/suspend', [Users::class, 'suspend'])->middleware($p('users.suspend'))->name('users.suspend');
        Route::post('users/{id}/reactivate', [Users::class, 'reactivate'])->middleware($p('users.suspend'))->name('users.reactivate');
        Route::patch('users/{id}/roles', [Users::class, 'roles'])->middleware($p('users.manage'))->name('users.roles');
        Route::post('users/{id}/deletion/approve', [Users::class, 'approveDeletion'])->middleware($p('users.manage'))->name('users.deletion.approve');
        Route::post('users/{id}/deletion/reject', [Users::class, 'rejectDeletion'])->middleware($p('users.manage'))->name('users.deletion.reject');
        Route::get('deletion-requests', [Users::class, 'deletionRequests'])->middleware($p('users.view'))->name('deletion-requests');

        // Events & categories
        Route::get('events', [Events::class, 'index'])->middleware($p('events.view'))->name('events.index');
        Route::get('events/{id}', [Events::class, 'show'])->middleware($p('events.view'))->name('events.show');
        Route::post('events/{id}/approve', [Events::class, 'approve'])->middleware($p('events.moderate'))->name('events.approve');
        Route::post('events/{id}/reject', [Events::class, 'reject'])->middleware($p('events.moderate'))->name('events.reject');
        Route::post('events/{id}/suspend', [Events::class, 'suspend'])->middleware($p('events.moderate'))->name('events.suspend');
        Route::post('events/{id}/restore', [Events::class, 'restore'])->middleware($p('events.moderate'))->name('events.restore');
        Route::post('events/{id}/feature', [Events::class, 'feature'])->middleware($p('events.feature'))->name('events.feature');
        Route::post('events/{id}/unfeature', [Events::class, 'unfeature'])->middleware($p('events.feature'))->name('events.unfeature');
        Route::get('categories', [Events::class, 'categories'])->middleware($p('events.view'))->name('categories.index');
        Route::post('categories', [Events::class, 'createCategory'])->middleware($p('categories.manage'))->name('categories.create');
        Route::put('categories/{id}', [Events::class, 'updateCategory'])->middleware($p('categories.manage'))->name('categories.update');
        Route::delete('categories/{id}', [Events::class, 'deleteCategory'])->middleware($p('categories.manage'))->name('categories.delete');

        // Organizers & vendors
        Route::get('organizers', [Organizers::class, 'index'])->middleware($p('organizers.view'))->name('organizers.index');
        Route::get('organizers/{id}', [Organizers::class, 'show'])->middleware($p('organizers.view'))->name('organizers.show');
        Route::post('organizers/{id}/verify', [Organizers::class, 'verify'])->middleware($p('organizers.verify'))->name('organizers.verify');
        Route::post('organizers/{id}/reject', [Organizers::class, 'reject'])->middleware($p('organizers.verify'))->name('organizers.reject');
        Route::post('organizers/{id}/suspend', [Organizers::class, 'suspend'])->middleware($p('organizers.manage'))->name('organizers.suspend');
        Route::post('organizers/{id}/reactivate', [Organizers::class, 'reactivate'])->middleware($p('organizers.manage'))->name('organizers.reactivate');
        Route::put('organizers/{id}/commission', [Organizers::class, 'commission'])->middleware($p('finance.commission.manage'))->name('organizers.commission');

        // Bookings
        Route::get('bookings', [Bookings::class, 'index'])->middleware($p('bookings.view'))->name('bookings.index');
        Route::get('bookings/{id}', [Bookings::class, 'show'])->middleware($p('bookings.view'))->name('bookings.show');
        Route::post('bookings/{id}/refund', [Bookings::class, 'refund'])->middleware($p('refunds.approve'))->name('bookings.refund');
        Route::post('bookings/{id}/resend', [Bookings::class, 'resend'])->middleware($p('bookings.manage'))->name('bookings.resend');
        Route::get('disputes', [Bookings::class, 'disputes'])->middleware($p('bookings.view'))->name('disputes.index');
        Route::patch('disputes/{id}', [Bookings::class, 'updateDispute'])->middleware($p('bookings.manage'))->name('disputes.update');
        Route::get('check-ins', [Bookings::class, 'checkIns'])->middleware($p('bookings.view'))->name('check-ins');
        Route::get('inventory', [Bookings::class, 'inventory'])->middleware($p('bookings.view'))->name('inventory');

        // Finance
        Route::prefix('finance')->name('finance.')->group(function () use ($p) {
            Route::get('summary', [Finance::class, 'summary'])->middleware($p('finance.view'))->name('summary');
            Route::get('transactions', [Finance::class, 'transactions'])->middleware($p('finance.view'))->name('transactions');
            Route::post('transactions/{id}/reconcile', [Finance::class, 'reconcile'])->middleware($p('finance.reconcile'))->name('transactions.reconcile');
            Route::get('settlements', [Finance::class, 'settlements'])->middleware($p('finance.settlements'))->name('settlements');
            Route::post('settlements/{id}/hold', [Finance::class, 'hold'])->middleware($p('finance.settlements'))->name('settlements.hold');
            Route::post('settlements/{id}/release', [Finance::class, 'release'])->middleware($p('finance.settlements'))->name('settlements.release');
            Route::get('payouts', [Finance::class, 'payouts'])->middleware($p('finance.view'))->name('payouts');
            Route::post('payouts', [Finance::class, 'createPayout'])->middleware($p('finance.settlements'))->name('payouts.create');
            Route::post('payouts/{id}/approve', [Finance::class, 'approvePayout'])->middleware($p('finance.payouts.approve'))->name('payouts.approve');
            Route::post('payouts/{id}/reject', [Finance::class, 'rejectPayout'])->middleware($p('finance.payouts.approve'))->name('payouts.reject');
            Route::get('refunds', [Finance::class, 'refunds'])->middleware($p('refunds.view'))->name('refunds');
            Route::post('refunds/{id}/approve', [Finance::class, 'approveRefund'])->middleware($p('refunds.approve'))->name('refunds.approve');
            Route::post('refunds/{id}/reject', [Finance::class, 'rejectRefund'])->middleware($p('refunds.approve'))->name('refunds.reject');
            Route::get('commissions', [Finance::class, 'commissions'])->middleware($p('finance.view'))->name('commissions');
            Route::post('commissions', [Finance::class, 'createCommission'])->middleware($p('finance.commission.manage'))->name('commissions.create');
            Route::put('commissions/{id}', [Finance::class, 'updateCommission'])->middleware($p('finance.commission.manage'))->name('commissions.update');
            Route::get('gateways', [Finance::class, 'gateways'])->middleware($p('finance.view'))->name('gateways');
        });

        // Moderation
        Route::get('moderation/reports', [Moderation::class, 'reports'])->middleware($p('moderation.view'))->name('moderation.reports');
        Route::get('moderation/reports/{id}', [Moderation::class, 'show'])->middleware($p('moderation.view'))->name('moderation.reports.show');
        Route::patch('moderation/reports/{id}', [Moderation::class, 'update'])->middleware($p('moderation.act'))->name('moderation.reports.update');
        Route::post('moderation/reports/{id}/actions', [Moderation::class, 'act'])->middleware($p('moderation.act'))->name('moderation.reports.act');
        Route::get('communities', [Moderation::class, 'communities'])->middleware($p('moderation.view'))->name('communities.index');
        Route::post('communities/{id}/suspend', [Moderation::class, 'suspendCommunity'])->middleware($p('moderation.act'))->name('communities.suspend');
        Route::post('communities/{id}/restore', [Moderation::class, 'restoreCommunity'])->middleware($p('moderation.act'))->name('communities.restore');
        Route::post('communities/{id}/archive', [Moderation::class, 'archiveCommunity'])->middleware($p('moderation.act'))->name('communities.archive');

        // Notifications & campaigns
        Route::get('notifications/campaigns', [Notifications::class, 'index'])->middleware($p('notifications.view'))->name('campaigns.index');
        Route::post('notifications/campaigns', [Notifications::class, 'store'])->middleware($p('notifications.send'))->name('campaigns.store');
        Route::get('notifications/campaigns/{id}', [Notifications::class, 'show'])->middleware($p('notifications.view'))->name('campaigns.show');
        Route::post('notifications/campaigns/{id}/send', [Notifications::class, 'send'])->middleware($p('notifications.send'))->name('campaigns.send');
        Route::post('notifications/campaigns/{id}/cancel', [Notifications::class, 'cancel'])->middleware($p('notifications.send'))->name('campaigns.cancel');
        Route::post('notifications/test', [Notifications::class, 'test'])->middleware($p('notifications.send'))->name('notifications.test');
        Route::get('notifications/templates', [Notifications::class, 'templates'])->middleware($p('notifications.view'))->name('templates.index');
        Route::post('notifications/templates', [Notifications::class, 'createTemplate'])->middleware($p('notifications.send'))->name('templates.create');
        Route::put('notifications/templates/{id}', [Notifications::class, 'updateTemplate'])->middleware($p('notifications.send'))->name('templates.update');

        // Platform configuration
        Route::get('settings', [Platform::class, 'settings'])->middleware($p('settings.view'))->name('settings');
        Route::put('settings', [Platform::class, 'updateSettings'])->middleware($p('settings.manage'))->name('settings.update');
        Route::get('banners', [Platform::class, 'banners'])->middleware($p('settings.view'))->name('banners.index');
        Route::post('banners', [Platform::class, 'createBanner'])->middleware($p('settings.manage'))->name('banners.create');
        Route::put('banners/{id}', [Platform::class, 'updateBanner'])->middleware($p('settings.manage'))->name('banners.update');
        Route::delete('banners/{id}', [Platform::class, 'deleteBanner'])->middleware($p('settings.manage'))->name('banners.delete');
        Route::get('pages', [Platform::class, 'pages'])->middleware($p('settings.view'))->name('pages.index');
        Route::put('pages/{slug}', [Platform::class, 'updatePage'])->middleware($p('settings.manage'))->name('pages.update');

        // Roles, admins, audit
        Route::get('permissions', [Access::class, 'permissions'])->middleware($p('roles.manage'))->name('permissions');
        Route::get('roles', [Access::class, 'roles'])->middleware($p('roles.manage'))->name('roles.index');
        Route::post('roles', [Access::class, 'createRole'])->middleware($p('roles.manage'))->name('roles.create');
        Route::put('roles/{id}', [Access::class, 'updateRole'])->middleware($p('roles.manage'))->name('roles.update');
        Route::delete('roles/{id}', [Access::class, 'deleteRole'])->middleware($p('roles.manage'))->name('roles.delete');
        Route::get('admins', [Access::class, 'admins'])->middleware($p('roles.manage'))->name('admins.index');
        Route::post('admins', [Access::class, 'invite'])->middleware($p('roles.manage'))->name('admins.invite');
        Route::patch('admins/{id}', [Access::class, 'update'])->middleware($p('roles.manage'))->name('admins.update');
        Route::get('audit-logs', [Access::class, 'auditLogs'])->middleware($p('audit.view'))->name('audit-logs');
        Route::get('security-logs', [Access::class, 'securityLogs'])->middleware($p('audit.view'))->name('security-logs');

        // Reports & exports
        Route::get('reports/definitions', [Reports::class, 'definitions'])->middleware($p('reports.view'))->name('reports.definitions');
        Route::post('reports/{key}/run', [Reports::class, 'run'])->middleware($p('reports.view'))->name('reports.run');
        Route::post('reports/{key}/export', [Reports::class, 'export'])->middleware($p('reports.export'))->name('reports.export');
        Route::get('exports/{id}', [Reports::class, 'show'])->middleware($p('reports.export'))->name('exports.show');

        // Support
        Route::get('support/tickets', [Support::class, 'index'])->middleware($p('support.view'))->name('support.index');
        Route::get('support/tickets/{id}', [Support::class, 'show'])->middleware($p('support.view'))->name('support.show');
        Route::patch('support/tickets/{id}', [Support::class, 'update'])->middleware($p('support.manage'))->name('support.update');
        Route::post('support/tickets/{id}/messages', [Support::class, 'reply'])->middleware($p('support.manage'))->name('support.reply');
    });
});
