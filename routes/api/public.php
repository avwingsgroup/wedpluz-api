<?php

/*
| Public and optional-auth routes. `auth.optional` personalises responses when
| a valid customer token is sent and returns 401 for an invalid one.
*/

use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\PlatformController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\SystemController;
use App\Http\Controllers\Api\V1\VendorController;
use Illuminate\Support\Facades\Route;

// Signed, expiring downloads (signature checked in the controller).
Route::get('files/{upload}', [SystemController::class, 'privateFile'])->name('files.private');
Route::get('documents/tickets/{ticket}', [DocumentController::class, 'ticket'])->name('documents.ticket');
Route::get('documents/receipts/{order}', [DocumentController::class, 'receipt'])->name('documents.receipt');

Route::middleware(['throttle:public'])->group(function () {
    // Cacheable reference data.
    Route::middleware('cache.public:300')->group(function () {
        Route::get('interests', [PlatformController::class, 'interests'])->name('interests');
        Route::get('config', [PlatformController::class, 'config'])->name('config');
        Route::get('pages/{slug}', [PlatformController::class, 'page'])->name('pages.show');
        Route::get('categories', [EventController::class, 'categories'])->name('categories');
        Route::get('plans', [SubscriptionController::class, 'plans'])->name('plans');
        Route::get('vendor-categories', [VendorController::class, 'categories'])->name('vendor-categories');
        Route::get('business-categories', [VendorController::class, 'businessCategories'])->name('business-categories');
    });

    Route::get('search/suggestions', [EventController::class, 'suggestions'])->middleware('throttle:suggestions')->name('search.suggestions');
    Route::get('locations/suggest', [EventController::class, 'locations'])->middleware('throttle:suggestions')->name('locations.suggest');

    Route::middleware('auth.optional')->group(function () {
        Route::get('users/{id}', [PlatformController::class, 'publicProfile'])->name('users.show');

        Route::get('events', [EventController::class, 'index'])->name('events.index');
        Route::get('events/featured', [EventController::class, 'featured'])->name('events.featured');
        Route::get('events/upcoming', [EventController::class, 'upcoming'])->name('events.upcoming');
        Route::get('events/popular', [EventController::class, 'popular'])->name('events.popular');
        Route::get('events/promoted', [EventController::class, 'promoted'])->name('events.promoted');
        Route::get('events/{id}/related', [EventController::class, 'related'])->name('events.related');
        Route::get('events/{id}/organizer-events', [EventController::class, 'organizerEvents'])->name('events.organizer-events');
        Route::get('events/{slug}', [EventController::class, 'show'])->name('events.show');

        Route::get('vendors', [VendorController::class, 'index'])->name('vendors.index');
        Route::get('vendors/featured', [VendorController::class, 'featured'])->name('vendors.featured');
        Route::get('vendors/{id}/works', [VendorController::class, 'works'])->name('vendors.works');
        Route::get('vendors/{id}/reviews', [VendorController::class, 'reviews'])->name('vendors.reviews');
        Route::get('vendors/{slug}', [VendorController::class, 'show'])->name('vendors.show');
        Route::get('works/{id}', [VendorController::class, 'work'])->name('works.show');
    });
});
