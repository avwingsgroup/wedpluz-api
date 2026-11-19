<?php

namespace App\Integrations\Payments;

use App\Exceptions\ApiException;
use App\Services\Platform\SettingsService;

/**
 * Resolves gateways by name and chooses a provider for a client-selected
 * payment method and currency. The backend owns the provider choice: the
 * client sends `paymentMethod` and invalid combinations are rejected (422).
 */
class PaymentManager
{
    /** @param array<string, PaymentGateway> $gateways */
    public function __construct(
        private readonly array $gateways,
        private readonly SettingsService $settings,
    ) {}

    public function gateway(string $provider): PaymentGateway
    {
        return $this->gateways[$provider] ?? throw new \InvalidArgumentException('Unknown payment provider '.$provider);
    }

    public function has(string $provider): bool
    {
        return isset($this->gateways[$provider]);
    }

    public function transferGateway(?string $provider = null): TransferGateway
    {
        $provider ??= (string) config('wedpluz.payouts.provider', 'paystack');
        $gateway = $this->gateways[$provider] ?? null;
        if (! $gateway instanceof TransferGateway) {
            throw new \InvalidArgumentException($provider.' does not support transfers');
        }

        return $gateway;
    }

    /**
     * Map a client payment method to a provider for this currency.
     * `card` -> the default provider (or first enabled) that accepts the currency;
     * `google_pay` -> Stripe Checkout.
     */
    public function resolveProvider(string $method, string $currency, string $field = 'paymentMethod'): string
    {
        $enabled = (array) $this->settings->get('payments.enabledProviders', []);

        $provider = match ($method) {
            'google_pay' => 'stripe',
            'card' => $this->defaultFor($currency, $enabled),
            default => $method,
        };

        if ($provider === null || ! $this->has($provider) || ! in_array($provider, $enabled, true)) {
            throw ApiException::validation('This payment method is not available.', [$field => ['The selected payment method is not available.']]);
        }
        if (! in_array($currency, $this->gateway($provider)->currencies(), true)) {
            throw ApiException::validation('This payment method does not support '.$currency.'.', [$field => ['This payment method does not support '.$currency.'.']]);
        }

        return $provider;
    }

    /** @param list<string> $enabled */
    private function defaultFor(string $currency, array $enabled): ?string
    {
        $default = (string) $this->settings->get('payments.defaultProvider', 'paystack');
        $candidates = array_values(array_unique([$default, ...$enabled]));
        foreach ($candidates as $name) {
            if ($name !== 'wallet' && isset($this->gateways[$name]) && in_array($name, $enabled, true)
                && in_array($currency, $this->gateways[$name]->currencies(), true)) {
                return $name;
            }
        }

        return null;
    }
}
