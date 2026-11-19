<?php

/*
| Customer routes: a customer bearer token is required (audience:customer).
| `verified.email` gates buying tickets, creating events and the wallet.
| `idempotent` enforces Idempotency-Key on payment-sensitive writes.
*/

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\OrganizerController;
use App\Http\Controllers\Api\V1\PlatformController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Controllers\Api\V1\VendorController;
use App\Http\Controllers\Api\V1\WalletController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'audience:customer', 'throttle:customer'])->group(function () {

    // ---- Account & profile ----------------------------------------------------
    Route::prefix('me')->name('me.')->group(function () {
        Route::get('/', [AccountController::class, 'show'])->name('show');
        Route::patch('/', [AccountController::class, 'update'])->name('update');
        Route::post('profile-setup', [AccountController::class, 'profileSetup'])->name('profile-setup');
        Route::put('interests', [AccountController::class, 'interests'])->name('interests');
        Route::put('password', [AccountController::class, 'password'])->name('password');
        Route::get('privacy', [AccountController::class, 'privacy'])->name('privacy');
        Route::put('privacy', [AccountController::class, 'updatePrivacy'])->name('privacy.update');
        Route::get('notification-preferences', [AccountController::class, 'notificationPreferences'])->name('notification-preferences');
        Route::put('notification-preferences', [AccountController::class, 'updateNotificationPreferences'])->name('notification-preferences.update');
        Route::get('sessions', [AccountController::class, 'sessions'])->name('sessions');
        Route::delete('sessions', [AccountController::class, 'revokeOtherSessions'])->name('sessions.revoke-others');
        Route::delete('sessions/{id}', [AccountController::class, 'revokeSession'])->name('sessions.revoke');
        Route::get('identity-verification', [AccountController::class, 'identity'])->name('identity');
        Route::post('identity-verification', [AccountController::class, 'submitIdentity'])->name('identity.submit');
        Route::get('deletion-request', [AccountController::class, 'deletionRequest'])->name('deletion');
        Route::post('deletion-request', [AccountController::class, 'requestDeletion'])->name('deletion.request');
        Route::delete('deletion-request', [AccountController::class, 'cancelDeletion'])->name('deletion.cancel');
        Route::post('devices', [AccountController::class, 'registerDevice'])->name('devices');

        Route::get('saved-events', [EventController::class, 'saved'])->name('saved-events');
        Route::get('saved-vendors', [VendorController::class, 'saved'])->name('saved-vendors');
        Route::get('tickets', [TicketController::class, 'index'])->name('tickets');
        Route::get('collaborations', [OrganizerController::class, 'collaborations'])->name('collaborations');
        Route::patch('collaborations/{id}', [OrganizerController::class, 'respondCollaboration'])->name('collaborations.respond');
        Route::get('promotions', [OrganizerController::class, 'myPromotions'])->name('promotions');
        Route::get('promotions/{id}', [OrganizerController::class, 'myPromotion'])->name('promotions.show');

        Route::get('vendor-profile', [VendorController::class, 'myProfile'])->name('vendor-profile');
        Route::post('vendor-profile', [VendorController::class, 'createProfile'])->name('vendor-profile.create');
        Route::put('vendor-profile', [VendorController::class, 'updateProfile'])->name('vendor-profile.update');
        Route::delete('vendor-profile', [VendorController::class, 'deleteProfile'])->name('vendor-profile.delete');
        Route::post('vendor-profile/works', [VendorController::class, 'createWork'])->name('vendor-profile.works.create');
        Route::put('vendor-profile/works/{id}', [VendorController::class, 'updateWork'])->name('vendor-profile.works.update');
        Route::delete('vendor-profile/works/{id}', [VendorController::class, 'deleteWork'])->name('vendor-profile.works.delete');
        Route::post('vendor-profile/promos', [VendorController::class, 'createPromo'])->name('vendor-profile.promos.create');
        Route::delete('vendor-profile/promos/{id}', [VendorController::class, 'deletePromo'])->name('vendor-profile.promos.delete');

        Route::get('subscription', [SubscriptionController::class, 'show'])->name('subscription');
        Route::get('subscription/payments', [SubscriptionController::class, 'payments'])->name('subscription.payments');
        Route::post('subscription', [SubscriptionController::class, 'subscribe'])->middleware('idempotent')->name('subscription.subscribe');
        Route::delete('subscription', [SubscriptionController::class, 'cancel'])->name('subscription.cancel');
    });

    Route::post('uploads', [PlatformController::class, 'upload'])->name('uploads.store');
    Route::post('uploads/sign', [PlatformController::class, 'signUpload'])->name('uploads.sign');
    Route::post('support/tickets', [PlatformController::class, 'openTicket'])->name('support.store');
    Route::get('support/tickets', [PlatformController::class, 'tickets'])->name('support.index');
    Route::post('reports', [PlatformController::class, 'report'])->name('reports.store');

    // ---- Event discovery (personal actions) ---------------------------------------
    Route::post('events/{id}/save', [EventController::class, 'save'])->name('events.save');
    Route::delete('events/{id}/save', [EventController::class, 'unsave'])->name('events.unsave');
    Route::post('events/{id}/requests', [EventController::class, 'requestToAttend'])->name('events.requests');
    Route::post('events/{eventId}/promotions/join', [OrganizerController::class, 'joinPromotion'])->name('events.promotions.join');

    // ---- Checkout, orders, tickets ------------------------------------------------
    Route::middleware('verified.email')->group(function () {
        Route::post('checkout/quotes', [CheckoutController::class, 'quote'])->name('checkout.quote');
        Route::post('orders', [CheckoutController::class, 'createOrder'])->middleware('idempotent')->name('orders.create');
    });
    Route::delete('checkout/quotes/{quoteId}', [CheckoutController::class, 'releaseQuote'])->name('checkout.release');
    Route::get('events/{eventId}/schedules/{scheduleId}/seats', [CheckoutController::class, 'seats'])->name('events.seats');
    Route::get('orders/{id}', [CheckoutController::class, 'showOrder'])->name('orders.show');
    Route::post('orders/{id}/verify-payment', [CheckoutController::class, 'verifyPayment'])->name('orders.verify');
    Route::get('orders/{id}/receipt', [CheckoutController::class, 'receipt'])->name('orders.receipt');
    Route::get('tickets/{id}', [TicketController::class, 'show'])->name('tickets.show');
    Route::post('tickets/{id}/cancel', [TicketController::class, 'cancel'])->name('tickets.cancel');
    Route::post('tickets/{id}/transfer', [TicketController::class, 'transfer'])->name('tickets.transfer');
    Route::get('tickets/{id}/download', [TicketController::class, 'download'])->name('tickets.download');

    // ---- Organizer portal ----------------------------------------------------------
    Route::prefix('organizer/events')->name('organizer.')->group(function () {
        Route::get('/', [OrganizerController::class, 'index'])->name('index');
        Route::post('/', [OrganizerController::class, 'store'])->middleware('verified.email')->name('store');
        Route::get('{id}', [OrganizerController::class, 'show'])->name('show');
        Route::put('{id}', [OrganizerController::class, 'update'])->name('update');
        Route::delete('{id}', [OrganizerController::class, 'destroy'])->name('destroy');
        Route::post('{id}/publish', [OrganizerController::class, 'publish'])->middleware('verified.email')->name('publish');
        Route::patch('{id}/status', [OrganizerController::class, 'status'])->name('status');
        Route::get('{id}/analytics', [OrganizerController::class, 'analytics'])->name('analytics');
        Route::get('{id}/guests', [OrganizerController::class, 'guests'])->name('guests');
        Route::get('{id}/requests', [OrganizerController::class, 'requests'])->name('requests');
        Route::post('{id}/requests/bulk', [OrganizerController::class, 'bulkDecide'])->name('requests.bulk');
        Route::patch('{id}/requests/{requestId}', [OrganizerController::class, 'decideRequest'])->name('requests.decide');
        Route::get('{id}/check-ins', [OrganizerController::class, 'checkIns'])->name('check-ins');
        Route::post('{id}/check-ins', [OrganizerController::class, 'checkIn'])->name('check-ins.create');
        Route::get('{id}/managers', [OrganizerController::class, 'managers'])->name('managers');
        Route::post('{id}/managers', [OrganizerController::class, 'inviteManager'])->name('managers.invite');
        Route::patch('{id}/managers/{managerId}', [OrganizerController::class, 'updateManager'])->name('managers.update');
        Route::delete('{id}/managers/{managerId}', [OrganizerController::class, 'removeManager'])->name('managers.remove');
        Route::get('{id}/vendors', [OrganizerController::class, 'vendors'])->name('vendors');
        Route::post('{id}/vendors', [OrganizerController::class, 'inviteVendor'])->name('vendors.invite');
        Route::delete('{id}/vendors/{assignmentId}', [OrganizerController::class, 'removeVendor'])->name('vendors.remove');
        Route::get('{id}/tasks', [OrganizerController::class, 'tasks'])->name('tasks');
        Route::post('{id}/tasks', [OrganizerController::class, 'createTask'])->name('tasks.create');
        Route::patch('{id}/tasks/{taskId}', [OrganizerController::class, 'updateTask'])->name('tasks.update');
        Route::delete('{id}/tasks/{taskId}', [OrganizerController::class, 'deleteTask'])->name('tasks.delete');
        Route::get('{id}/activity', [OrganizerController::class, 'activity'])->name('activity');
        Route::get('{id}/promotion', [OrganizerController::class, 'promotion'])->name('promotion');
        Route::put('{id}/promotion', [OrganizerController::class, 'updatePromotion'])->name('promotion.update');
        Route::get('{id}/promoters', [OrganizerController::class, 'promoters'])->name('promoters');
        Route::get('{id}/community', [OrganizerController::class, 'community'])->name('community');
        Route::put('{id}/community', [OrganizerController::class, 'updateCommunity'])->name('community.update');
    });

    // ---- Wallet ----------------------------------------------------------------------
    Route::prefix('wallet')->name('wallet.')->middleware('verified.email')->group(function () {
        Route::get('/', [WalletController::class, 'show'])->name('show');
        Route::post('activate', [WalletController::class, 'activate'])->name('activate');
        Route::post('pin', [WalletController::class, 'pin'])->name('pin');
        Route::get('transactions', [WalletController::class, 'transactions'])->name('transactions');
        Route::get('transactions/{id}', [WalletController::class, 'transaction'])->name('transactions.show');
        Route::get('bank-accounts', [WalletController::class, 'bankAccounts'])->name('bank-accounts');
        Route::post('bank-accounts/resolve', [WalletController::class, 'resolve'])->name('bank-accounts.resolve');
        Route::post('bank-accounts', [WalletController::class, 'addBankAccount'])->name('bank-accounts.add');
        Route::delete('bank-accounts/{id}', [WalletController::class, 'removeBankAccount'])->name('bank-accounts.remove');
        Route::post('top-ups', [WalletController::class, 'topUp'])->middleware('idempotent')->name('top-ups');
        Route::post('top-ups/{id}/verify', [WalletController::class, 'verifyTopUp'])->name('top-ups.verify');
        Route::post('withdrawals', [WalletController::class, 'withdraw'])->middleware('idempotent')->name('withdrawals');
    });
    Route::get('banks', [WalletController::class, 'banks'])->name('banks');

    require __DIR__.'/customer_social.php';
});
