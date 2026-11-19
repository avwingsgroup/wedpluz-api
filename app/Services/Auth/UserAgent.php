<?php

namespace App\Services\Auth;

/** Minimal user-agent parsing for the sessions list (device + browser labels). */
final class UserAgent
{
    /** @return array{device:string, browser:string} */
    public static function describe(?string $ua): array
    {
        $ua = (string) $ua;
        if ($ua === '') {
            return ['device' => 'Unknown device', 'browser' => 'Unknown'];
        }

        $device = match (true) {
            (bool) preg_match('/iPhone/i', $ua) => 'iPhone',
            (bool) preg_match('/iPad/i', $ua) => 'iPad',
            (bool) preg_match('/Android/i', $ua) => 'Android device',
            (bool) preg_match('/Windows/i', $ua) => 'Windows PC',
            (bool) preg_match('/Macintosh|Mac OS X/i', $ua) => 'Mac',
            (bool) preg_match('/Linux/i', $ua) => 'Linux PC',
            (bool) preg_match('/okhttp|Dart|CFNetwork|Wedpluz/i', $ua) => 'Wedpluz app',
            default => 'Unknown device',
        };

        $browser = match (true) {
            (bool) preg_match('/Edg\//', $ua) => 'Edge',
            (bool) preg_match('/OPR\/|Opera/', $ua) => 'Opera',
            (bool) preg_match('/Chrome\//', $ua) => 'Chrome',
            (bool) preg_match('/Firefox\//', $ua) => 'Firefox',
            (bool) preg_match('/Safari\//', $ua) => 'Safari',
            (bool) preg_match('/okhttp|Dart|CFNetwork|Wedpluz/i', $ua) => 'Wedpluz app',
            default => 'Unknown',
        };

        return ['device' => $device, 'browser' => $browser];
    }
}
