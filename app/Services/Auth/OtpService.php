<?php

namespace App\Services\Auth;

use App\Enums\OtpPurpose;
use App\Exceptions\ApiException;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Platform\SettingsService;
use App\Support\Codes;
use Illuminate\Support\Facades\DB;

/**
 * One-time codes (authentication-and-permissions.md §3): 4 digits, 10-minute
 * expiry, 5 attempts then invalidated, stored hashed (HMAC with the app key),
 * a resend replaces the previous code. Sending never reveals whether an
 * account exists.
 */
class OtpService
{
    private const PURPOSE_LABELS = [
        'email_verification' => 'verify your email',
        'password_reset' => 'reset your password',
        'wallet_setup' => 'set up your wallet',
        'business_email' => 'confirm your business email',
    ];

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
    ) {}

    /** @return array{sent:bool, expiresInSeconds:int, channel:string} */
    public function send(string $email, string $purpose): array
    {
        $email = mb_strtolower(trim($email));
        $ttl = (int) config('wedpluz.otp.ttl_minutes', 10);
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        $eligible = match ($purpose) {
            OtpPurpose::EmailVerification->value => $user !== null && ! $user->hasVerifiedEmail(),
            OtpPurpose::PasswordReset->value, OtpPurpose::WalletSetup->value => $user !== null && ! $user->isSuspended(),
            OtpPurpose::BusinessEmail->value => true,
            default => false,
        };

        if ($eligible) {
            $code = $this->issue($email, $purpose, $user);
            $this->notifications->sendDirect('email', $email, 'otp_code', [
                'code' => $code,
                'purpose' => self::PURPOSE_LABELS[$purpose] ?? 'continue',
                'minutes' => $ttl,
                'firstName' => $user?->first_name ?? 'there',
            ], 'otp:'.$purpose.':'.$email.':'.now()->timestamp, $user);

            if ($user?->phone && $user->phone_verified_at && $this->settings->feature('smsOtpEnabled') && $purpose !== OtpPurpose::BusinessEmail->value) {
                $this->notifications->sendDirect('sms', $user->phone, 'otp_code', ['code' => $code, 'minutes' => $ttl], 'otp-sms:'.$purpose.':'.$email.':'.now()->timestamp, $user);
            }
        }

        return ['sent' => true, 'expiresInSeconds' => $ttl * 60, 'channel' => 'email'];
    }

    /** Create a code, invalidating any previous unconsumed code for this email and purpose. */
    public function issue(string $email, string $purpose, ?User $user = null): string
    {
        $code = Codes::otp((int) config('wedpluz.otp.length', 4));

        DB::transaction(function () use ($email, $purpose, $user, $code) {
            OtpCode::query()->where('email', $email)->where('purpose', $purpose)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            OtpCode::query()->create([
                'email' => $email,
                'user_id' => $user?->id,
                'purpose' => $purpose,
                'code_hash' => $this->hash($code),
                'expires_at' => now()->addMinutes((int) config('wedpluz.otp.ttl_minutes', 10)),
            ]);
        });

        return $code;
    }

    /**
     * Check a code. Wrong codes count attempts; the fifth wrong attempt
     * invalidates the code. `consume` marks it used (single use).
     */
    public function verify(string $email, string $purpose, string $code, bool $consume = true, string $field = 'code'): OtpCode
    {
        $email = mb_strtolower(trim($email));
        $max = (int) config('wedpluz.otp.max_attempts', 5);

        // Attempt counting must persist even when the code is wrong, so the
        // error is raised after the transaction commits.
        [$otp, $error] = DB::transaction(function () use ($email, $purpose, $code, $consume, $max) {
            $otp = OtpCode::query()->where('email', $email)->where('purpose', $purpose)->whereNull('consumed_at')
                ->latest('id')->lockForUpdate()->first();

            if ($otp === null || $otp->expires_at->isPast() || $otp->attempts >= $max) {
                return [null, 'expired'];
            }

            if (! $this->matches($otp, $code)) {
                $otp->attempts++;
                if ($otp->attempts >= $max) {
                    $otp->consumed_at = now();
                }
                $otp->save();

                return [null, $otp->attempts >= $max ? 'expired' : 'invalid:'.($max - $otp->attempts)];
            }

            $otp->verified_at ??= now();
            if ($consume) {
                $otp->consumed_at = now();
            }
            $otp->save();

            return [$otp, null];
        });

        if ($error === 'expired') {
            throw ApiException::otpExpired('This code has expired. Request a new one.', $field);
        }
        if ($error !== null) {
            $left = (int) substr($error, 8);
            throw ApiException::otpInvalid('The code you entered is incorrect. '.$left.' attempt'.($left === 1 ? '' : 's').' left.', $field);
        }

        return $otp;
    }

    private function matches(OtpCode $otp, string $code): bool
    {
        $fixed = config('wedpluz.auth.dev_fixed_otp');
        if ($fixed && ! app()->isProduction() && hash_equals((string) $fixed, $code)) {
            return true;
        }

        return hash_equals($otp->code_hash, $this->hash($code));
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
