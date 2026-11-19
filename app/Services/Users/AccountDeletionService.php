<?php

namespace App\Services\Users;

use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\AccountDeletionRequest;
use App\Models\IdentityVerification;
use App\Models\Upload;
use App\Models\User;
use App\Services\Auth\TokenService;
use App\Services\Media\UploadService;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Account deletion with a 30-day grace period (business-rules.md, "Account
 * deletion and privacy"). Completion anonymises personal data but keeps
 * financial records with the anonymised user reference.
 */
class AccountDeletionService
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly NotificationService $notifications,
        private readonly UploadService $uploads,
    ) {}

    public function pending(User $user): ?AccountDeletionRequest
    {
        return AccountDeletionRequest::query()->where('user_id', $user->id)->where('status', 'pending')->first();
    }

    public function request(User $user, string $reason, string $password): AccountDeletionRequest
    {
        if ($user->password !== null && ! Hash::check($password, $user->password)) {
            throw ApiException::validation('Your password is incorrect.', ['password' => ['Your password is incorrect.']]);
        }
        if ($this->pending($user)) {
            throw ApiException::conflict('You already have a pending deletion request.');
        }
        if ($blocker = $this->blocker($user)) {
            throw ApiException::conflict($blocker);
        }

        $request = DB::transaction(function () use ($user, $reason) {
            $request = AccountDeletionRequest::query()->create([
                'user_id' => $user->id,
                'reason' => $reason,
                'status' => 'pending',
                'requested_at' => now(),
                'scheduled_for' => now()->addDays((int) config('wedpluz.account_deletion.grace_days', 30)),
            ]);
            $user->forceFill(['status' => UserStatus::PendingDeletion])->save();
            DB::table('user_privacy_settings')->where('user_id', $user->id)->update(['discoverable_by_search' => false]);
            $this->tokens->revokeAllForUser($user);

            return $request;
        });

        $this->notifications->sendDirect('email', $user->email, 'account_deletion', [
            'firstName' => $user->first_name,
            'scheduledFor' => $request->scheduled_for->format('j M Y'),
        ], 'deletion:'.$request->id, $user);

        return $request;
    }

    public function cancel(User $user): void
    {
        DB::transaction(function () use ($user) {
            $request = AccountDeletionRequest::query()->where('user_id', $user->id)->where('status', 'pending')->lockForUpdate()->first();
            if ($request === null) {
                throw ApiException::notFound('No pending deletion request.');
            }
            $request->forceFill(['status' => 'cancelled', 'decided_at' => now()])->save();
            if ($user->status === UserStatus::PendingDeletion) {
                $user->forceFill(['status' => UserStatus::Active])->save();
            }
            DB::table('user_privacy_settings')->where('user_id', $user->id)->update(['discoverable_by_search' => true]);
        });
    }

    /** Reasons the request must be refused (409), with what to resolve. */
    public function blocker(User $user): ?string
    {
        $wallet = DB::table('wallets')->where('user_id', $user->id)->first();
        if ($wallet && ($wallet->cached_balance > 0 || $wallet->cached_pending_balance > 0)) {
            return 'Withdraw your wallet balance before deleting your account.';
        }
        if ($wallet && DB::table('wallet_transactions')->where('wallet_id', $wallet->id)->where('type', 'withdrawal')->whereIn('status', ['pending', 'processing'])->exists()) {
            return 'Wait for your pending withdrawals to complete before deleting your account.';
        }
        if (DB::table('settlements')->where('organizer_user_id', $user->id)->whereIn('status', ['pending', 'ready', 'on_hold', 'failed'])->exists()) {
            return 'You have unsettled event payouts. Wait for them to be paid before deleting your account.';
        }
        $upcoming = DB::table('events')->where('organizer_user_id', $user->id)->whereNull('deleted_at')
            ->whereIn('status', ['live', 'pending_review'])->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->exists();
        if ($upcoming) {
            return 'Cancel or complete your upcoming events before deleting your account.';
        }

        return null;
    }

    /** Anonymise the account (after the grace period or on early admin approval). */
    public function complete(AccountDeletionRequest $request, ?string $adminId = null): void
    {
        $user = User::query()->withTrashed()->find($request->user_id);
        if ($user === null || $user->anonymized_at !== null) {
            return;
        }

        $selfies = IdentityVerification::query()->where('user_id', $user->id)->pluck('selfie_upload_id')->filter();

        DB::transaction(function () use ($request, $user, $adminId) {
            $request->forceFill(['status' => 'completed', 'completed_at' => now(), 'decided_by_admin_id' => $adminId ?? $request->decided_by_admin_id, 'decided_at' => $request->decided_at ?? now()])->save();

            $user->forceFill([
                'first_name' => 'Deleted',
                'last_name' => 'user',
                'display_name' => 'Deleted user',
                'username' => null,
                'email' => 'deleted+'.strtolower($user->id).'@deleted.wedpluz.invalid',
                'phone' => null,
                'date_of_birth' => null,
                'password' => null,
                'avatar_url' => null,
                'bio' => null,
                'city' => null,
                'status' => UserStatus::Deactivated,
                'anonymized_at' => now(),
            ])->save();

            $this->tokens->revokeAllForUser($user);
            DB::table('social_accounts')->where('user_id', $user->id)->delete();
            DB::table('device_tokens')->where('user_id', $user->id)->delete();
            DB::table('user_interests')->where('user_id', $user->id)->delete();
            DB::table('community_members')->where('user_id', $user->id)->where('role', '!=', 'owner')->delete();
            DB::table('saved_events')->where('user_id', $user->id)->delete();
            DB::table('saved_vendors')->where('user_id', $user->id)->delete();
            DB::table('vendor_profiles')->where('user_id', $user->id)->whereNull('deleted_at')->update(['deleted_at' => now(), 'status' => 'suspended']);
            DB::table('identity_verifications')->where('user_id', $user->id)->update(['id_number_encrypted' => encrypt('[deleted]'), 'id_number_last4' => null]);
            $user->delete(); // soft delete: hidden everywhere; financial rows keep the reference
        });

        foreach (Upload::query()->whereIn('id', $selfies)->get() as $upload) {
            $this->uploads->delete($upload);
        }
    }
}
