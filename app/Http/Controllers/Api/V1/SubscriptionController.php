<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subscriptions\SubscribeRequest;
use App\Http\Resources\Fmt;
use App\Models\Plan;
use App\Models\SubscriptionPayment;
use App\Services\Subscriptions\SubscriptionService;
use App\Support\Platform;
use Illuminate\Http\JsonResponse;

class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function plans(): JsonResponse
    {
        return $this->ok(Plan::query()->orderBy('sort_order')->get()->map(fn (Plan $p) => SubscriptionService::plan($p))->all());
    }

    public function show(): JsonResponse
    {
        $user = $this->customer();

        return $this->ok($this->subscriptions->resource($this->subscriptions->current($user), $user));
    }

    public function subscribe(SubscribeRequest $request): JsonResponse
    {
        $result = $this->subscriptions->subscribe(
            $this->customer(),
            $request->string('planId'),
            $request->string('interval'),
            $request->string('paymentMethod'),
            (string) $request->attributes->get('idempotency_key'),
            (string) ($request->input('returnUrl') ?: Platform::webUrl('/pricing/return')),
        );

        return $this->ok($result);
    }

    public function cancel(): JsonResponse
    {
        $user = $this->customer();

        return $this->ok($this->subscriptions->resource($this->subscriptions->cancel($user), $user), 'Your plan will end at the end of the billing period.');
    }

    public function payments(): JsonResponse
    {
        $payments = SubscriptionPayment::query()->with('plan')->where('user_id', $this->customer()->id)
            ->whereIn('status', ['successful', 'failed', 'reversed'])->latest()->limit(100)->get();

        return $this->ok($payments->map(fn (SubscriptionPayment $p) => [
            'id' => $p->id,
            'reference' => $p->reference,
            'plan' => ['id' => $p->plan->id, 'name' => $p->plan->name, 'tier' => $p->plan->tier],
            'amount' => $p->amount,
            'currency' => $p->currency,
            'status' => $p->status,
            'paidAt' => Fmt::date($p->paid_at ?? $p->created_at),
            'receiptUrl' => $p->receipt_url,
        ])->all());
    }
}
