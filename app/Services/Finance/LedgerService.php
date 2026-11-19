<?php

namespace App\Services\Finance;

use App\Models\LedgerEntry;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Append-only double-entry ledger. Every posting is a set of entries whose
 * debits equal credits. Wallet accounts (`wallet:{id}`, `wallet_pending:{id}`)
 * are liabilities: credits increase the balance, debits decrease it. Cached
 * wallet balances are updated in the same database transaction as the
 * entries, never on their own.
 *
 * Accounts used:
 *   provider:{name}            cash held at a payment provider
 *   organizer_payable:{userId} ticket revenue owed to an organiser
 *   platform:fees              service fees
 *   platform:commission        platform commission retained at settlement
 *   promoter_payable           promoter commissions not yet credited
 *   wallet:{id} / wallet_pending:{id}
 *   withdrawals_in_transit     wallet withdrawals sent to the bank, unconfirmed
 *   payouts_in_transit         organiser payouts sent to the bank, unconfirmed
 *   subscriptions:revenue      plan revenue
 */
class LedgerService
{
    /**
     * @param  list<array{account:string, direction:'debit'|'credit', amount:int}>  $entries
     */
    public function post(array $entries, string $currency, ?string $referenceType = null, ?string $referenceId = null, ?string $description = null): string
    {
        $entries = array_values(array_filter($entries, fn ($e) => $e['amount'] > 0));
        if ($entries === []) {
            return '';
        }
        $debits = array_sum(array_map(fn ($e) => $e['direction'] === 'debit' ? $e['amount'] : 0, $entries));
        $credits = array_sum(array_map(fn ($e) => $e['direction'] === 'credit' ? $e['amount'] : 0, $entries));
        if ($debits !== $credits) {
            throw new LogicException("Unbalanced ledger posting: debits $debits != credits $credits");
        }
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Ledger postings must run inside a database transaction.');
        }

        $transactionId = 'ltx_'.Str::ulid();
        $now = now();
        $rows = [];
        $walletDeltas = [];
        foreach ($entries as $e) {
            $rows[] = [
                'transaction_id' => $transactionId,
                'account' => $e['account'],
                'direction' => $e['direction'],
                'amount' => $e['amount'],
                'currency' => $currency,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description ? mb_substr($description, 0, 255) : null,
                'created_at' => $now,
            ];
            if (preg_match('/^(wallet|wallet_pending):(.+)$/', $e['account'], $m)) {
                $column = $m[1] === 'wallet' ? 'cached_balance' : 'cached_pending_balance';
                $delta = $e['direction'] === 'credit' ? $e['amount'] : -$e['amount'];
                $walletDeltas[$m[2]][$column] = ($walletDeltas[$m[2]][$column] ?? 0) + $delta;
            }
        }
        LedgerEntry::query()->insert($rows);

        foreach ($walletDeltas as $walletId => $deltas) {
            $update = [];
            foreach ($deltas as $column => $delta) {
                $update[$column] = DB::raw($column.' + ('.(int) $delta.')');
            }
            // The wallets_balance_check constraint rejects negative balances.
            Wallet::query()->whereKey($walletId)->update($update);
        }

        return $transactionId;
    }

    /** Balance of an account derived from the ledger (credits minus debits). */
    public function balance(string $account): int
    {
        $row = DB::table('ledger_entries')->where('account', $account)
            ->selectRaw("coalesce(sum(case when direction = 'credit' then amount else -amount end), 0) as balance")
            ->first();

        return (int) $row->balance;
    }

    public static function debit(string $account, int $amount): array
    {
        return ['account' => $account, 'direction' => 'debit', 'amount' => $amount];
    }

    public static function credit(string $account, int $amount): array
    {
        return ['account' => $account, 'direction' => 'credit', 'amount' => $amount];
    }
}
