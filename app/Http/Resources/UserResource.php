<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/** The contract's `User` (the signed-in customer). @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $interests = $this->relationLoaded('interests')
            ? $this->interests->pluck('slug')->all()
            : DB::table('user_interests')->where('user_id', $this->id)->orderBy('interest_slug')->pluck('interest_slug')->all();
        $vendorId = DB::table('vendor_profiles')->where('user_id', $this->id)->whereNull('deleted_at')->value('id');
        $walletActive = (bool) DB::table('wallets')->where('user_id', $this->id)->value('is_active');

        return [
            'id' => $this->id,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'displayName' => $this->display_name,
            'username' => $this->username,
            'email' => $this->email,
            'phone' => $this->phone,
            'dateOfBirth' => Fmt::day($this->date_of_birth),
            'emailVerifiedAt' => Fmt::date($this->email_verified_at),
            'phoneVerifiedAt' => Fmt::date($this->phone_verified_at),
            'identityStatus' => $this->identity_status->value,
            'interests' => $interests,
            'roles' => $this->roleList(),
            'status' => $this->status->value,
            'plan' => $this->plan,
            'locale' => $this->locale,
            'city' => $this->city,
            'countryCode' => $this->country_code,
            'avatarUrl' => $this->avatar_url,
            'isVerified' => $this->identity_status->value === 'verified',
            'isVendor' => (bool) $this->is_vendor,
            'businessProfileId' => $vendorId,
            'walletEnabled' => $walletActive,
            'createdAt' => Fmt::date($this->created_at),
            'lastSeenAt' => Fmt::date($this->last_seen_at),
        ];
    }
}
