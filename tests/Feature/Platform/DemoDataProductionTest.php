<?php

use App\Exceptions\ApiException;
use App\Models\NotificationDelivery;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\LedgerService;
use App\Services\Finance\PayoutService;
use App\Services\Notifications\NotificationService;
use App\Services\Wallet\WalletService;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

function actAsProduction(): void
{
    app()['env'] = 'production';
}

/** Runs the seeder without db:seed's production confirmation prompt. */
function seedDemo(): void
{
    app(DemoDataSeeder::class)->setContainer(app())->__invoke();
}

it('refuses demo data in production unless explicitly allowed', function () {
    $this->seed(ReferenceDataSeeder::class);
    actAsProduction();

    expect(fn () => seedDemo())->toThrow(RuntimeException::class, 'DEMO_DATA_IN_PRODUCTION');
    $this->artisan('wedpluz:demo')->assertFailed();
    // Removing only touches demo data, so it stays available to clean up a server.
    $this->artisan('wedpluz:demo --remove')->assertSuccessful();
    expect(User::query()->count())->toBe(0);
});

it('requires a demo password in production', function () {
    $this->seed(ReferenceDataSeeder::class);
    actAsProduction();
    config(['wedpluz.demo.allow_in_production' => true, 'wedpluz.demo.password' => 'short']);

    expect(fn () => seedDemo())->toThrow(RuntimeException::class, 'DEMO_DATA_PASSWORD');
});

it('seeds demo data in production with guardrails', function () {
    $this->seed(ReferenceDataSeeder::class);
    actAsProduction();
    config(['wedpluz.demo.allow_in_production' => true, 'wedpluz.demo.password' => 'Prod-demo-pass-42']);

    seedDemo();

    expect(User::query()->where('email', 'like', '%@'.DemoDataSeeder::DOMAIN)->count())->toBe(28)
        // No Super Admin with a shared password on a live server.
        ->and(DB::table('admin_users')->where('email', 'admin@'.DemoDataSeeder::DOMAIN)->exists())->toBeFalse();

    $customer = User::query()->where('email', 'customer@'.DemoDataSeeder::DOMAIN)->firstOrFail();
    expect(Hash::check('Prod-demo-pass-42', $customer->password))->toBeTrue()
        ->and(Hash::check(DemoDataSeeder::PASSWORD, $customer->password))->toBeFalse();

    // Demo balances never leave the platform.
    expect(fn () => app(WalletService::class)->withdraw($customer->forceFill(['identity_status' => 'verified']), 100000, 'any', '1234', 'demo-wd'))
        ->toThrow(ApiException::class, 'Demo accounts cannot withdraw');

    $settlement = Settlement::query()->firstOrFail();
    expect(fn () => app(PayoutService::class)->create([$settlement->id], $this->adminUser()))
        ->toThrow(ApiException::class, 'Demo organisers cannot be paid out');
});

it('lets demo wallets pay only for demo events in production', function () {
    $this->configurePaystack();
    $realEvent = $this->liveEvent();
    $demo = $this->asCustomer($this->customer(['email' => 'tester@'.DemoDataSeeder::DOMAIN]));
    $wallet = Wallet::query()->create(['user_id' => $demo->id, 'currency' => 'NGN', 'is_active' => true, 'pin_hash' => Hash::make('1234'), 'daily_withdrawal_limit' => 50000000]);
    DB::transaction(fn () => app(LedgerService::class)->post([
        LedgerService::debit('provider:paystack', 5000000),
        LedgerService::credit($wallet->availableAccount(), 5000000),
    ], 'NGN', 'test', null, 'seed'));
    $quote = $this->postJson('/api/v1/checkout/quotes', ['eventId' => $realEvent->id, 'lines' => [['ticketTypeId' => $realEvent->ticketTypes()->first()->id, 'quantity' => 1]]])->json('data');
    actAsProduction();

    $this->withHeader('Idempotency-Key', 'demo-wallet-order')->postJson('/api/v1/orders', [
        'quoteId' => $quote['quoteId'],
        'attendee' => ['firstName' => 'Demo', 'lastName' => 'Tester', 'email' => 'tester@'.DemoDataSeeder::DOMAIN],
        'paymentMethod' => 'wallet',
        'returnUrl' => 'https://wedpluz.com/checkout/return',
    ])->assertForbidden();

    expect($wallet->refresh()->cached_balance)->toBe(5000000);
});

it('never emails, texts or WhatsApps demo accounts', function () {
    $this->fakeQueue();
    $demo = $this->customer(['email' => 'tester@'.DemoDataSeeder::DOMAIN, 'phone' => '+2348031000000', 'phone_verified_at' => now()]);
    $real = $this->customer();
    $notifications = app(NotificationService::class);

    expect($notifications->sendDirect('sms', $demo->phone, 'otp_code', ['code' => '123456', 'minutes' => 10], null, $demo))->toBeNull()
        ->and($notifications->sendDirect('email', $demo->email, 'otp_code', ['code' => '123456', 'minutes' => 10]))->toBeNull()
        ->and($notifications->sendDirect('email', $real->email, 'otp_code', ['code' => '123456', 'minutes' => 10], null, $real))->not->toBeNull();
    expect(NotificationDelivery::query()->where('user_id', $demo->id)->count())->toBe(0);
});
