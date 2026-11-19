<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\ActivateWalletRequest;
use App\Http\Requests\Wallet\AddBankRequest;
use App\Http\Requests\Wallet\PinRequest;
use App\Http\Requests\Wallet\ResolveBankRequest;
use App\Http\Requests\Wallet\TopUpRequest;
use App\Http\Requests\Wallet\WithdrawRequest;
use App\Models\BankAccount;
use App\Models\WalletTransaction;
use App\Services\Wallet\BankAccountService;
use App\Services\Wallet\WalletService;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WalletController extends Controller
{
    public function __construct(private readonly WalletService $wallets, private readonly BankAccountService $banks) {}

    public function show(): JsonResponse
    {
        return $this->ok(WalletService::resource($this->wallets->forUser($this->customer())));
    }

    public function activate(ActivateWalletRequest $request): JsonResponse
    {
        return $this->ok(WalletService::resource($this->wallets->activate($this->customer(), $request->string('otpCode'))), 'Wallet activated.');
    }

    public function pin(PinRequest $request): JsonResponse
    {
        $this->wallets->setPin($this->customer(), $request->validated());

        return $this->ok(['pinSet' => true], 'Wallet PIN saved.');
    }

    public function transactions(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);
        $wallet = $this->wallets->forUser($this->customer());
        $query = WalletTransaction::query()->where('wallet_id', $wallet->id)
            ->when($list->filter('type'), fn ($q, $type) => $q->where('type', $type))
            ->orderByDesc('created_at')->orderByDesc('id');

        return $this->paginated($list->paginate($query), fn (WalletTransaction $t) => WalletService::transaction($t));
    }

    public function transaction(string $id): JsonResponse
    {
        $wallet = $this->wallets->forUser($this->customer());
        $tx = WalletTransaction::query()->where('wallet_id', $wallet->id)->find($id) ?? throw ApiException::notFound('Transaction not found.');

        return $this->ok(WalletService::transaction($tx));
    }

    public function banks(): JsonResponse
    {
        return $this->ok($this->banks->banks());
    }

    public function bankAccounts(): JsonResponse
    {
        return $this->ok(BankAccount::query()->where('user_id', $this->customer()->id)->orderByDesc('is_default')->orderBy('created_at')
            ->get()->map(fn (BankAccount $b) => WalletService::bankAccount($b))->all());
    }

    public function resolve(ResolveBankRequest $request): JsonResponse
    {
        return $this->ok($this->banks->resolve($request->string('bankCode'), $request->string('accountNumber')));
    }

    public function addBankAccount(AddBankRequest $request): JsonResponse
    {
        return $this->created(WalletService::bankAccount($this->banks->add($this->customer(), $request->string('bankCode'), $request->string('accountNumber'))), 'Bank account added.');
    }

    public function removeBankAccount(string $id): Response
    {
        $this->banks->remove($this->customer(), $id);

        return $this->noContent();
    }

    public function topUp(TopUpRequest $request): JsonResponse
    {
        $result = $this->wallets->topUp($this->customer(), (int) $request->input('amount'), $request->string('method'), $request->string('returnUrl'), (string) $request->attributes->get('idempotency_key'));

        return $this->ok(['transaction' => WalletService::transaction($result['transaction']), 'authorizationUrl' => $result['authorizationUrl']]);
    }

    public function verifyTopUp(string $id): JsonResponse
    {
        return $this->ok(WalletService::transaction($this->wallets->verifyTopUp($this->customer(), $id)));
    }

    public function withdraw(WithdrawRequest $request): JsonResponse
    {
        $tx = $this->wallets->withdraw($this->customer(), (int) $request->input('amount'), $request->string('bankAccountId'), $request->string('pin'), (string) $request->attributes->get('idempotency_key'));

        return $this->ok(WalletService::transaction($tx), 'Withdrawal started.');
    }
}
