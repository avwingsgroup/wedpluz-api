<?php

namespace App\Services\Admin;

use App\Models\AdminUser;
use Illuminate\Support\Facades\DB;

/**
 * TOTP (RFC 6238: HMAC-SHA1, 30-second steps, 6 digits, ±1 step drift) for
 * mandatory admin 2FA. Each time step can be used once per admin, so a code
 * observed in transit cannot be replayed.
 */
class TwoFactorService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    public function provisioningUri(AdminUser $admin, string $secret): string
    {
        $issuer = rawurlencode('Wedpluz Admin');

        return 'otpauth://totp/'.$issuer.':'.rawurlencode($admin->email).'?secret='.$secret.'&issuer='.$issuer.'&algorithm=SHA1&digits=6&period=30';
    }

    /** Verify and consume a code for an admin (replay-protected). */
    public function verifyFor(AdminUser $admin, string $code): bool
    {
        $secret = (string) $admin->two_factor_secret;
        if ($secret === '' || ! preg_match('/^\d{6}$/', $code)) {
            return $this->devCode($code);
        }

        $step = $this->matchingStep($secret, $code);
        if ($step === null) {
            return $this->devCode($code);
        }

        // Atomically consume the step: fails if this or a later step was already used.
        $consumed = DB::table('admin_users')->where('id', $admin->id)
            ->where(fn ($q) => $q->whereNull('two_factor_last_used_step')->orWhere('two_factor_last_used_step', '<', $step))
            ->update(['two_factor_last_used_step' => $step]);

        return $consumed === 1;
    }

    public function matchingStep(string $secret, string $code, ?int $time = null): ?int
    {
        $key = $this->base32Decode($secret);
        $current = intdiv($time ?? time(), 30);
        foreach ([0, -1, 1] as $offset) {
            if (hash_equals($this->codeAt($key, $current + $offset), $code)) {
                return $current + $offset;
            }
        }

        return null;
    }

    public function codeAt(string $key, int $step): string
    {
        $binary = pack('N*', 0).pack('N*', $step);
        $hash = hash_hmac('sha1', $binary, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    public function currentCode(string $secret): string
    {
        return $this->codeAt($this->base32Decode($secret), intdiv(time(), 30));
    }

    private function devCode(string $code): bool
    {
        $fixed = config('wedpluz.auth.dev_fixed_otp');

        return $fixed && ! app()->isProduction() && hash_equals((string) $fixed, $code);
    }

    private function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret));
        $bits = '';
        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
