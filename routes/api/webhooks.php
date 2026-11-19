<?php

/*
| Provider webhooks (backend-only). Authenticated by signature, not tokens.
| `primely` is kept as an alias of `prembly` for the contract's path name.
*/

use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('webhooks')->name('webhooks.')->middleware('throttle:webhooks')->group(function () {
    Route::post('{provider}', [WebhookController::class, 'payment'])
        ->whereIn('provider', ['paystack', 'stripe', 'paypal', 'monnify', 'flutterwave'])->name('payment');
    Route::post('prembly', [WebhookController::class, 'prembly'])->name('prembly');
    Route::post('primely', [WebhookController::class, 'prembly'])->name('primely');
    Route::post('zeptomail', [WebhookController::class, 'zeptomail'])->name('zeptomail');
    Route::post('termii', [WebhookController::class, 'termii'])->name('termii');
    Route::post('twilio', [WebhookController::class, 'twilio'])->name('twilio');
    Route::get('whatsapp', [WebhookController::class, 'whatsappVerify'])->name('whatsapp.verify');
    Route::post('whatsapp', [WebhookController::class, 'whatsapp'])->name('whatsapp');
});
