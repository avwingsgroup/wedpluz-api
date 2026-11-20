<?php

/*
| Wedpluz REST API v1 — mounted at /api/v1 (bootstrap/app.php).
|
| Route files per audience:
|   routes/api/public.php    guests and optional-auth catalogue reads
|   routes/api/customer.php  customer token (audience:customer)
|   routes/api/admin.php     admin token (audience:admin) + one permission per route
|   routes/api/webhooks.php  provider callbacks, authenticated by signature
*/

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\SystemController;
use Illuminate\Support\Facades\Route;

Route::get('health', [SystemController::class, 'health'])->name('health');
Route::get('health/details', [SystemController::class, 'healthDetails'])->middleware('throttle:public')->name('health.details');

// ---- Authentication (public) ------------------------------------------------
Route::prefix('auth')->name('auth.')->group(function () {
    Route::middleware('throttle:login')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->name('login');
        Route::post('register', [AuthController::class, 'register'])->name('register');
        Route::post('social', [AuthController::class, 'social'])->name('social');
        Route::get('google/redirect', [AuthController::class, 'googleRedirect'])->name('google.redirect');
    });
    Route::middleware('throttle:otp')->group(function () {
        Route::post('otp/send', [AuthController::class, 'sendOtp'])->name('otp.send');
        Route::post('password/forgot', [AuthController::class, 'forgotPassword'])->name('password.forgot');
    });
    Route::middleware('throttle:public')->group(function () {
        Route::post('otp/verify', [AuthController::class, 'verifyOtp'])->name('otp.verify');
        Route::post('password/reset', [AuthController::class, 'resetPassword'])->name('password.reset');
        Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');
    });
    Route::middleware(['auth:sanctum', 'audience:customer', 'throttle:customer'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
    });
});

require __DIR__.'/api/public.php';
require __DIR__.'/api/customer.php';
require __DIR__.'/api/admin.php';
require __DIR__.'/api/webhooks.php';
