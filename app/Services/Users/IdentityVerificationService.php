<?php

namespace App\Services\Users;

use App\Enums\IdentityStatus;
use App\Exceptions\ApiException;
use App\Http\Resources\Fmt;
use App\Integrations\Identity\IdentityResult;
use App\Integrations\Identity\IdentityVerifier;
use App\Jobs\VerifyIdentityJob;
use App\Models\IdentityVerification;
use App\Models\User;
use App\Services\Media\UploadService;
use App\Services\Notifications\NotificationService;
use App\Services\Platform\AlertService;
use App\Services\Realtime\Realtime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Identity verification (KYC) via Prembly. Status changes only after the
 * provider result arrives (sync job result or webhook); the client never sets
 * `verified`. ID numbers are encrypted at rest; only reference, outcome and
 * match score are kept. ID types the provider cannot check are routed to
 * manual admin review (organizer verification).
 */
class IdentityVerificationService
{
    public const ID_FORMATS = [
        'nin' => ['/^\d{11}$/', 'A NIN is 11 digits.'],
        'bvn' => ['/^\d{11}$/', 'A BVN is 11 digits.'],
        'passport' => ['/^[A-Z]\d{8}$/', 'Enter a valid passport number (a letter followed by 8 digits).'],
        'drivers_license' => ['/^[A-Z0-9]{8,20}$/', 'Enter a valid driver\'s licence number.'],
        'resident_card' => ['/^[A-Z0-9]{6,20}$/', 'Enter a valid resident card number.'],
    ];

    public function __construct(
        private readonly UploadService $uploads,
        private readonly IdentityVerifier $verifier,
        private readonly NotificationService $notifications,
        private readonly AlertService $alerts,
    ) {}

    public function latest(User $user): ?IdentityVerification
    {
        return IdentityVerification::query()->where('user_id', $user->id)->latest('created_at')->first();
    }

    public function submit(User $user, string $idType, string $idNumber, string $selfieUploadId): IdentityVerification
    {
        $idNumber = strtoupper(preg_replace('/\s+/', '', $idNumber));
        [$pattern, $message] = self::ID_FORMATS[$idType] ?? [null, 'Unsupported ID type.'];
        if ($pattern === null || ! preg_match($pattern, $idNumber)) {
            throw ApiException::validation($message, ['idNumber' => [$message]]);
        }

        $verification = DB::transaction(function () use ($user, $idType, $idNumber, $selfieUploadId) {
            $current = IdentityVerification::query()->where('user_id', $user->id)->latest('created_at')->lockForUpdate()->first();
            if ($current?->status === 'verified') {
                throw ApiException::conflict('Your identity is already verified.');
            }
            if ($current?->status === 'pending') {
                throw ApiException::conflict('A verification is already in progress.');
            }

            $selfie = $this->uploads->consume($selfieUploadId, $user, 'identity_selfie', 'selfieUploadId');

            $verification = IdentityVerification::query()->create([
                'user_id' => $user->id,
                'id_type' => $idType,
                'id_number_encrypted' => $idNumber,
                'id_number_last4' => substr($idNumber, -4),
                'selfie_upload_id' => $selfie->id,
                'provider' => $this->verifier->name(),
                'status' => 'pending',
                'submitted_at' => now(),
            ]);
            $user->forceFill(['identity_status' => IdentityStatus::Pending])->save();

            return $verification;
        });

        VerifyIdentityJob::dispatch($verification->id);

        return $verification;
    }

    /** Called by the queued job: run the provider check. */
    public function runCheck(IdentityVerification $verification): void
    {
        if ($verification->status !== 'pending') {
            return;
        }
        if (! $this->verifier->supports($verification->id_type)) {
            $this->alerts->raise('warning', 'Identity verification needs manual review',
                'A '.$verification->id_type.' verification cannot be checked automatically.', '/organizers?identityStatus=pending', 'idv-manual:'.$verification->id);

            return;
        }

        $selfie = $verification->selfie;
        $bytes = $selfie && $selfie->disk_path ? Storage::disk('private')->get($selfie->disk_path) : null;
        if ($bytes === null) {
            $this->apply($verification, new IdentityResult('failed', null, null, 'Your selfie could not be read. Please submit again.'));

            return;
        }

        $result = $this->verifier->verify($verification->id_type, $verification->id_number_encrypted, $bytes, $verification->id);
        $this->apply($verification, $result);
    }

    /** Apply a provider or admin decision. Idempotent for repeated webhooks. */
    public function apply(IdentityVerification $verification, IdentityResult $result): IdentityVerification
    {
        if ($result->status === 'pending') {
            if ($result->providerReference) {
                $verification->forceFill(['provider_reference' => $result->providerReference])->save();
            }

            return $verification;
        }

        $changed = DB::transaction(function () use ($verification, $result) {
            $locked = IdentityVerification::query()->whereKey($verification->id)->lockForUpdate()->first();
            if ($locked->status !== 'pending') {
                return false;
            }
            $locked->forceFill([
                'status' => $result->status,
                'provider_reference' => $result->providerReference ?? $locked->provider_reference,
                'match_score' => $result->matchScore,
                'failure_reason' => $result->status === 'failed' ? $result->failureReason : null,
                'reviewed_at' => now(),
            ])->save();

            $latestId = IdentityVerification::query()->where('user_id', $locked->user_id)->latest('created_at')->value('id');
            if ($latestId === $locked->id) {
                User::query()->whereKey($locked->user_id)->update(['identity_status' => $result->status]);
            }
            $verification->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if ($changed) {
            $user = User::query()->find($verification->user_id);
            $verified = $result->status === 'verified';
            $this->notifications->notify($user, $verified ? 'identity_verified' : 'identity_failed', [
                'email' => ['status' => $verified ? 'successful' : 'unsuccessful', 'reason' => $verified ? '' : (string) $result->failureReason],
                'data' => ['verificationId' => $verification->id],
            ]);
            Realtime::toUser($user->id, 'identity.updated', self::resource($verification));
        }

        return $verification;
    }

    /** IdentityVerification resource shape. */
    public static function resource(?IdentityVerification $v): ?array
    {
        return $v === null ? null : [
            'id' => $v->id,
            'status' => $v->status,
            'idType' => $v->id_type,
            'submittedAt' => Fmt::date($v->submitted_at),
            'reviewedAt' => Fmt::date($v->reviewed_at),
            'failureReason' => $v->failure_reason,
            'provider' => $v->provider,
        ];
    }
}
