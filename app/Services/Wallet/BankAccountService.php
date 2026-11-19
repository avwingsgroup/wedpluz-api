<?php

namespace App\Services\Wallet;

use App\Exceptions\ApiException;
use App\Integrations\Payments\PaymentManager;
use App\Integrations\Support\IntegrationException;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Payout bank accounts. The account name is always resolved through the
 * provider and stored as returned; a client-supplied name is ignored. At most
 * three accounts per user, one default. Adding an account starts the payout
 * cool-down for organisers (business-rules.md, "Settlements and payouts").
 */
class BankAccountService
{
    public function __construct(private readonly PaymentManager $payments, private readonly NotificationService $notifications) {}

    /** @return list<array{code:string,name:string}> */
    public function banks(): array
    {
        try {
            $banks = $this->payments->transferGateway()->banks();
            foreach ($banks as $bank) {
                Bank::query()->updateOrCreate(['code' => $bank['code']], ['name' => $bank['name'], 'is_active' => true]);
            }

            return $banks;
        } catch (IntegrationException) {
            return Bank::query()->where('is_active', true)->orderBy('name')->get(['code', 'name'])->map(fn ($b) => ['code' => $b->code, 'name' => $b->name])->all();
        }
    }

    /** @return array{accountName:string, bankName:string} */
    public function resolve(string $bankCode, string $accountNumber): array
    {
        if (! preg_match('/^\d{10}$/', $accountNumber)) {
            throw ApiException::validation('Enter a valid 10-digit account number.', ['accountNumber' => ['Account numbers are 10 digits.']]);
        }
        try {
            $resolved = $this->payments->transferGateway()->resolveAccount($bankCode, $accountNumber);
        } catch (IntegrationException $e) {
            throw ApiException::validation(
                $e->transient ? 'Bank verification is temporarily unavailable. Please try again.' : 'We could not verify this account number.',
                ['accountNumber' => [$e->transient ? 'Try again shortly.' : 'Check the account number and bank.']],
            );
        }

        return ['accountName' => $resolved['accountName'], 'bankName' => $resolved['bankName'] ?? $this->bankName($bankCode)];
    }

    public function add(User $user, string $bankCode, string $accountNumber): BankAccount
    {
        $resolved = $this->resolve($bankCode, $accountNumber);
        $hash = hash_hmac('sha256', $bankCode.':'.$accountNumber, (string) config('app.key'));

        $account = DB::transaction(function () use ($user, $bankCode, $accountNumber, $resolved, $hash) {
            $existing = BankAccount::query()->where('user_id', $user->id)->lockForUpdate()->get();
            if ($existing->contains('account_number_hash', $hash)) {
                throw ApiException::conflict('This bank account is already saved.');
            }
            if ($existing->count() >= (int) config('wedpluz.wallet.max_bank_accounts', 3)) {
                throw ApiException::validation('You can save up to 3 bank accounts.', ['accountNumber' => ['Remove an account to add a new one.']]);
            }

            return BankAccount::query()->create([
                'user_id' => $user->id,
                'bank_code' => $bankCode,
                'bank_name' => $resolved['bankName'],
                'account_number_encrypted' => $accountNumber,
                'account_number_hash' => $hash,
                'account_last4' => substr($accountNumber, -4),
                'account_name' => $resolved['accountName'],
                'is_default' => $existing->isEmpty(),
                'verified_at' => now(),
            ]);
        });

        $this->notifications->notify($user, 'system', [
            'title' => 'Bank account added',
            'body' => 'A bank account ending '.$account->account_last4.' was added. Payouts to new accounts start after a 48-hour security cool-down.',
            'data' => ['bankAccountId' => $account->id],
        ]);

        return $account;
    }

    public function remove(User $user, string $id): void
    {
        DB::transaction(function () use ($user, $id) {
            $account = BankAccount::query()->where('user_id', $user->id)->lockForUpdate()->find($id);
            if ($account === null) {
                throw ApiException::notFound('Bank account not found.');
            }
            $pending = DB::table('wallet_transactions')->where('bank_account_id', $id)->whereIn('status', ['pending', 'processing'])->exists();
            if ($pending) {
                throw ApiException::conflict('This account has a withdrawal in progress.');
            }
            $account->delete();
            if ($account->is_default) {
                BankAccount::query()->where('user_id', $user->id)->orderBy('created_at')->limit(1)->update(['is_default' => true]);
            }
        });
    }

    private function bankName(string $code): string
    {
        $name = Bank::query()->whereKey($code)->value('name');
        if ($name) {
            return $name;
        }
        $bank = collect($this->banks())->firstWhere('code', $code);

        return $bank['name'] ?? $code;
    }
}
