<?php

use App\Models\BankAccount;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Auth\OtpService;
use App\Services\Finance\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

/** An active wallet with a PIN and a funded balance posted through the ledger. */
function fundedWallet($user, int $balance = 5000000): Wallet
{
    $wallet = Wallet::query()->create(['user_id' => $user->id, 'currency' => 'NGN', 'is_active' => true, 'pin_hash' => Hash::make('1234'), 'daily_withdrawal_limit' => 50000000]);
    DB::transaction(fn () => app(LedgerService::class)->post([
        LedgerService::debit('provider:paystack', $balance),
        LedgerService::credit($wallet->availableAccount(), $balance),
    ], 'NGN', 'test', null, 'seed'));

    return $wallet->refresh();
}

function bankAccount($user): BankAccount
{
    return BankAccount::query()->create([
        'user_id' => $user->id, 'bank_code' => '058', 'bank_name' => 'GTBank', 'account_number_encrypted' => '0123456789',
        'account_number_hash' => hash('sha256', 'x'.uniqid()), 'account_last4' => '6789', 'account_name' => 'ADA OKAFOR', 'is_default' => true, 'verified_at' => now(),
    ]);
}

beforeEach(fn () => $this->configurePaystack());

it('activates the wallet with a one-time code', function () {
    $user = $this->asCustomer();
    $code = app(OtpService::class)->issue($user->email, 'wallet_setup', $user);

    $this->postJson('/api/v1/wallet/activate', ['otpCode' => $code])->assertOk()->assertJsonPath('data.isActive', true)->assertJsonPath('data.pinSet', false);
    $this->postJson('/api/v1/wallet/pin', ['pin' => '4321', 'pinConfirmation' => '4321'])->assertOk()->assertJsonPath('data.pinSet', true);
});

it('requires a verified identity to withdraw', function () {
    $user = $this->asCustomer();
    fundedWallet($user);
    $bank = bankAccount($user);

    $this->withHeader('Idempotency-Key', 'wd-1')->postJson('/api/v1/wallet/withdrawals', ['amount' => 200000, 'bankAccountId' => $bank->id, 'pin' => '1234'])
        ->assertStatus(403)->assertJsonPath('code', 'IDENTITY_UNVERIFIED');
});

it('debits immediately, sends the transfer and settles on success', function () {
    $user = $this->asCustomer($this->customer(['identity_status' => 'verified']));
    $wallet = fundedWallet($user);
    $bank = bankAccount($user);
    Http::fake([
        'api.paystack.co/transferrecipient' => Http::response(['status' => true, 'data' => ['recipient_code' => 'RCP_1']]),
        'api.paystack.co/transfer' => Http::response(['status' => true, 'data' => ['status' => 'success', 'transfer_code' => 'TRF_1']]),
    ]);

    $this->withHeader('Idempotency-Key', 'wd-ok')->postJson('/api/v1/wallet/withdrawals', ['amount' => 200000, 'bankAccountId' => $bank->id, 'pin' => '1234'])->assertOk();

    expect($wallet->refresh()->cached_balance)->toBe(4800000)
        ->and(app(LedgerService::class)->balance($wallet->availableAccount()))->toBe(4800000)
        ->and(WalletTransaction::query()->where('type', 'withdrawal')->value('status')->value)->toBe('successful')
        ->and(app(LedgerService::class)->balance('withdrawals_in_transit'))->toBe(0);
});

it('re-credits the wallet when the bank transfer fails', function () {
    $user = $this->asCustomer($this->customer(['identity_status' => 'verified']));
    $wallet = fundedWallet($user);
    $bank = bankAccount($user);
    Http::fake([
        'api.paystack.co/transferrecipient' => Http::response(['status' => true, 'data' => ['recipient_code' => 'RCP_1']]),
        'api.paystack.co/transfer' => Http::response(['status' => true, 'data' => ['status' => 'failed', 'transfer_code' => 'TRF_2']]),
    ]);

    $this->withHeader('Idempotency-Key', 'wd-fail')->postJson('/api/v1/wallet/withdrawals', ['amount' => 200000, 'bankAccountId' => $bank->id, 'pin' => '1234'])->assertOk();

    expect($wallet->refresh()->cached_balance)->toBe(5000000)
        ->and(WalletTransaction::query()->where('type', 'withdrawal')->value('status')->value)->toBe('reversed');
});

it('rejects withdrawals above the balance and locks after five wrong PINs', function () {
    $user = $this->asCustomer($this->customer(['identity_status' => 'verified']));
    fundedWallet($user, 300000);
    $bank = bankAccount($user);

    $this->withHeader('Idempotency-Key', 'wd-big')->postJson('/api/v1/wallet/withdrawals', ['amount' => 400000, 'bankAccountId' => $bank->id, 'pin' => '1234'])
        ->assertStatus(402)->assertJsonPath('code', 'INSUFFICIENT_FUNDS');

    foreach (range(1, 5) as $i) {
        $this->withHeader('Idempotency-Key', 'wd-pin-'.$i)->postJson('/api/v1/wallet/withdrawals', ['amount' => 100000, 'bankAccountId' => $bank->id, 'pin' => '0000'])
            ->assertStatus(422)->assertJsonPath('code', 'PIN_INVALID');
    }
    // Even the correct PIN is refused while locked.
    $this->withHeader('Idempotency-Key', 'wd-pin-ok')->postJson('/api/v1/wallet/withdrawals', ['amount' => 100000, 'bankAccountId' => $bank->id, 'pin' => '1234'])
        ->assertStatus(422)->assertJsonPath('code', 'PIN_INVALID');
    expect(Wallet::query()->where('user_id', $user->id)->value('pin_locked_until'))->not->toBeNull();
});

it('pays for tickets from the wallet inside the order transaction', function () {
    $event = $this->liveEvent();
    $user = $this->asCustomer();
    $wallet = fundedWallet($user);
    $type = $event->ticketTypes()->first();
    $quote = $this->postJson('/api/v1/checkout/quotes', ['eventId' => $event->id, 'lines' => [['ticketTypeId' => $type->id, 'quantity' => 1]]])->json('data');

    $this->withHeader('Idempotency-Key', 'w-order')->postJson('/api/v1/orders', [
        'quoteId' => $quote['quoteId'],
        'attendee' => ['firstName' => 'Ada', 'lastName' => 'Obi', 'email' => 'ada@example.com'],
        'paymentMethod' => 'wallet',
        'returnUrl' => 'https://wedpluz.com/checkout/return',
    ])->assertCreated()->assertJsonPath('data.order.status', 'paid');

    expect($wallet->refresh()->cached_balance)->toBe(5000000 - $quote['total']);
    $sum = DB::table('ledger_entries')->selectRaw("sum(case when direction='debit' then amount else -amount end) as d")->value('d');
    expect((int) $sum)->toBe(0);
});
