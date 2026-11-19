<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Http\Middleware\RequestContext;
use App\Models\AdminUser;
use App\Models\PersonalAccessToken;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Customer and admin token issuance.
 *
 * Customers: Sanctum access token (ability `customer`, 60 min) + opaque refresh
 * token (30 days, sliding). Refresh rotates both; presenting a revoked refresh
 * token again revokes its whole family (reuse detection). A session
 * (GET /me/sessions) is one refresh-token family.
 *
 * Admins: access token only (ability `admin`, 8 h hard expiry, 30 min idle).
 */
class TokenService
{
    public function __construct(private readonly Request $request) {}

    /** @return array{accessToken:string, refreshToken:string, tokenType:string, expiresIn:int, expiresAt:string} */
    public function issueCustomer(User $user, ?string $deviceName = null, ?string $familyId = null): array
    {
        $accessTtl = (int) config('wedpluz.auth.customer_access_ttl_minutes', 60);
        $expiresAt = now()->addMinutes($accessTtl);
        $device = $this->deviceName($deviceName);

        $access = $user->createToken($device, ['customer'], $expiresAt);
        /** @var PersonalAccessToken $model */
        $model = $access->accessToken;
        $model->forceFill([
            'ip_address' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 512),
            'platform' => RequestContext::source($this->request),
        ])->save();

        $plainRefresh = Str::random(64);
        RefreshToken::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainRefresh),
            'family_id' => $familyId ?? 'ses_'.Str::ulid(),
            'access_token_id' => $model->id,
            'expires_at' => now()->addDays((int) config('wedpluz.auth.customer_refresh_ttl_days', 30)),
            'device_name' => $device,
            'ip_address' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 512),
            'platform' => RequestContext::source($this->request),
            'last_used_at' => now(),
        ]);

        return [
            'accessToken' => $access->plainTextToken,
            'refreshToken' => $plainRefresh,
            'tokenType' => 'Bearer',
            'expiresIn' => $accessTtl * 60,
            'expiresAt' => $expiresAt->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** POST /auth/refresh: rotate access and refresh tokens. */
    public function refresh(string $plainRefresh): array
    {
        // Outcomes that must persist (revocations) are committed before the error is thrown.
        $outcome = DB::transaction(function () use ($plainRefresh) {
            $token = RefreshToken::query()->where('token_hash', hash('sha256', $plainRefresh))->lockForUpdate()->first();
            if ($token === null || $token->expires_at->isPast()) {
                return 'invalid';
            }

            if ($token->revoked_at !== null) {
                // Reuse of a rotated/revoked token: assume theft, kill the family.
                $this->revokeFamily($token->family_id);

                return 'invalid';
            }

            $user = User::query()->find($token->user_id);
            if ($user === null) {
                return 'invalid';
            }
            if ($user->isSuspended()) {
                $this->revokeAllForUser($user);

                return 'suspended';
            }

            PersonalAccessToken::query()->whereKey($token->access_token_id)->delete();
            $tokens = $this->issueCustomer($user, $token->device_name, $token->family_id);
            $new = RefreshToken::query()->where('token_hash', hash('sha256', $tokens['refreshToken']))->first();
            $token->forceFill(['revoked_at' => now(), 'replaced_by_id' => $new?->id])->save();

            return $tokens;
        });

        return match ($outcome) {
            'invalid' => throw ApiException::unauthenticated(),
            'suspended' => throw ApiException::accountSuspended(),
            default => $outcome,
        };
    }

    /** Revoke the current access token and its refresh family. */
    public function revokeCurrent(User $user): void
    {
        $current = $user->currentAccessToken();
        if ($current instanceof PersonalAccessToken) {
            $family = RefreshToken::query()->where('access_token_id', $current->id)->value('family_id');
            if ($family) {
                $this->revokeFamily($family);
            }
            $current->delete();
        }
    }

    public function revokeFamily(string $familyId): void
    {
        $tokens = RefreshToken::query()->where('family_id', $familyId)->get(['id', 'access_token_id']);
        PersonalAccessToken::query()->whereIn('id', $tokens->pluck('access_token_id')->filter())->delete();
        RefreshToken::query()->where('family_id', $familyId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /** Revoke every session; optionally keep the current one (password change). */
    public function revokeAllForUser(User $user, bool $keepCurrent = false): void
    {
        $keepFamily = null;
        $current = $keepCurrent ? $user->currentAccessToken() : null;
        if ($current instanceof PersonalAccessToken) {
            $keepFamily = RefreshToken::query()->where('access_token_id', $current->id)->value('family_id');
        }

        $families = RefreshToken::query()->where('user_id', $user->id)->whereNull('revoked_at')
            ->when($keepFamily, fn ($q) => $q->where('family_id', '!=', $keepFamily))
            ->distinct()->pluck('family_id');
        foreach ($families as $family) {
            $this->revokeFamily($family);
        }

        PersonalAccessToken::query()
            ->where('tokenable_type', 'user')->where('tokenable_id', $user->id)
            ->when($current instanceof PersonalAccessToken, fn ($q) => $q->where('id', '!=', $current->id))
            ->delete();
    }

    /** @return array{accessToken:string, refreshToken:null, tokenType:string, expiresIn:int, expiresAt:string} */
    public function issueAdmin(AdminUser $admin): array
    {
        $ttl = (int) config('wedpluz.auth.admin_access_ttl_minutes', 480);
        $expiresAt = now()->addMinutes($ttl);
        $access = $admin->createToken('admin-console', ['admin'], $expiresAt);
        $access->accessToken->forceFill([
            'ip_address' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 512),
            'platform' => 'admin',
            'last_used_at' => now(),
        ])->save();

        return [
            'accessToken' => $access->plainTextToken,
            'refreshToken' => null,
            'tokenType' => 'Bearer',
            'expiresIn' => $ttl * 60,
            'expiresAt' => $expiresAt->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function deviceName(?string $given): string
    {
        if ($given !== null && trim($given) !== '') {
            return mb_substr(trim($given), 0, 255);
        }
        $ua = (string) $this->request->userAgent();

        return mb_substr(UserAgent::describe($ua)['device'], 0, 255);
    }
}
