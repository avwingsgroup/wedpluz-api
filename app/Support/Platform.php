<?php

namespace App\Support;

use App\Services\Platform\SettingsService;

/** Small static accessors over platform settings. */
final class Platform
{
    public static function settings(): SettingsService
    {
        return app(SettingsService::class);
    }

    public static function timezone(): string
    {
        return (string) (self::settings()->get('general.timezone') ?: config('wedpluz.timezone', 'Africa/Lagos'));
    }

    public static function setting(string $path, mixed $default = null): mixed
    {
        return self::settings()->get($path, $default);
    }

    public static function webUrl(string $path = ''): string
    {
        return rtrim((string) config('wedpluz.web_origin'), '/').'/'.ltrim($path, '/');
    }
}
