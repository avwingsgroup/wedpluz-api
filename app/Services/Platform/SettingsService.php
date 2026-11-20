<?php

namespace App\Services\Platform;

use App\Models\PlatformSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Platform settings (PlatformSettings in the contract): groups `general`,
 * `payments`, `features`, `app`, `legal`. Stored as jsonb per group, merged
 * over defaults, cached in Redis and busted on update. Never holds secrets.
 */
class SettingsService
{
    private const CACHE_KEY = 'platform_settings:v1';

    public const GROUPS = ['general', 'payments', 'features', 'app', 'legal'];

    /** @var array<string,mixed>|null */
    private ?array $memo = null;

    /** @return array<string, array<string, mixed>> */
    public function defaults(): array
    {
        $web = rtrim((string) config('wedpluz.web_origin'), '/');

        return [
            'general' => [
                'platformName' => 'Wedpluz',
                'supportEmail' => 'support@wedpluz.com',
                // Empty until an admin sets real numbers (Platform Configuration); the web app hides empty contact cards.
                'supportPhone' => '',
                'supportWhatsapp' => '',
                'defaultCurrency' => 'NGN',
                'defaultCountry' => 'NG',
                'timezone' => config('wedpluz.timezone', 'Africa/Lagos'),
                'maintenanceMode' => false,
            ],
            'payments' => [
                'enabledProviders' => ['paystack', 'monnify', 'flutterwave', 'stripe', 'paypal', 'wallet'],
                'defaultProvider' => 'paystack',
                'serviceFeePercent' => 3,
                'serviceFeeFixed' => 10000,
                'passFeesToBuyer' => true,
                'refundWindowHours' => 48,
                'settlementDelayDays' => 3,
                'minWithdrawal' => 100000,
                'maxWithdrawal' => 500000000,
            ],
            'features' => [
                'walletEnabled' => true,
                'promotionsEnabled' => true,
                'communitiesEnabled' => true,
                'liveSessionsEnabled' => false,
                'plannerEnabled' => true,
                'plannerAiEnabled' => false,
                'ticketTransferEnabled' => true,
                'smsOtpEnabled' => false,
                'whatsappNotificationsEnabled' => false,
            ],
            'app' => [
                'minAndroidVersion' => '1.0.0',
                'minIosVersion' => '1.0.0',
                'forceUpdate' => false,
                'androidStoreUrl' => 'https://play.google.com/store/apps/details?id=com.wedpluz.app',
                'iosStoreUrl' => 'https://apps.apple.com/app/wedpluz/id0000000000',
            ],
            'legal' => [
                'termsUrl' => $web.'/terms',
                'privacyUrl' => $web.'/privacy',
                'termsUpdatedAt' => '2026-01-01T00:00:00Z',
                'privacyUpdatedAt' => '2026-01-01T00:00:00Z',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $stored = Cache::remember(self::CACHE_KEY, 600, fn () => PlatformSetting::query()->pluck('value', 'key')->all());

        $merged = [];
        foreach ($this->defaults() as $group => $defaults) {
            $value = $stored[$group] ?? [];
            if (is_string($value)) {
                $value = json_decode($value, true) ?: [];
            }
            $merged[$group] = array_replace($defaults, is_array($value) ? $value : []);
        }

        return $this->memo = $merged;
    }

    public function get(string $path, mixed $default = null): mixed
    {
        return Arr::get($this->all(), $path, $default);
    }

    public function feature(string $flag): bool
    {
        return (bool) $this->get('features.'.$flag, false);
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @return array<string, array<string, mixed>>
     */
    public function update(array $groups, ?string $adminId): array
    {
        $current = $this->all();
        foreach ($groups as $group => $values) {
            if (! in_array($group, self::GROUPS, true) || ! is_array($values)) {
                continue;
            }
            $next = $group === 'features'
                ? array_replace($current['features'], array_map('boolval', $values))
                : array_replace($current[$group], array_intersect_key($values, $this->defaults()[$group]));

            PlatformSetting::query()->updateOrCreate(['key' => $group], ['value' => $next, 'updated_by_admin_id' => $adminId]);
        }
        $this->flush();

        return $this->all();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget('public_config:v2');
        $this->memo = null;
    }

    /** GET /config payload: public values only. */
    public function publicConfig(): array
    {
        return Cache::remember('public_config:v2', 300, function () {
            $s = $this->all();

            return [
                'features' => $s['features'],
                'payments' => [
                    'enabledProviders' => array_values($s['payments']['enabledProviders']),
                    'serviceFeePercent' => $s['payments']['serviceFeePercent'],
                ],
                'support' => [
                    'email' => $s['general']['supportEmail'],
                    'phone' => $s['general']['supportPhone'],
                    'whatsapp' => $s['general']['supportWhatsapp'],
                ],
                'app' => $s['app'],
            ];
        });
    }
}
