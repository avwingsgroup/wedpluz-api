<?php

namespace App\Integrations\Payments;

/** Bank payouts: organiser settlements and wallet withdrawals. */
interface TransferGateway
{
    public function name(): string;

    /** @return list<array{code:string, name:string}> */
    public function banks(): array;

    /** @return array{accountName:string, bankName:?string} */
    public function resolveAccount(string $bankCode, string $accountNumber): array;

    public function transfer(TransferRequest $request): TransferResult;
}
