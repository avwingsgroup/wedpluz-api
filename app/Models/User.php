<?php

namespace App\Models;

use App\Enums\IdentityStatus;
use App\Enums\UserStatus;
use App\Jobs\ImportRemoteImageJob;
use App\Support\Concerns\HasPrefixedId;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

/**
 * A customer account: attendee, organiser, manager or vendor. A separate table
 * and token audience from AdminUser.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPrefixedId, Notifiable, SoftDeletes;

    protected static string $idPrefix = 'usr';

    protected $guarded = [];

    protected $hidden = ['password'];

    /** Remote images (e.g. Google profile pictures) are copied into Cloudinary after save. */
    protected static function booted(): void
    {
        static::saved(function (self $model) {
            if ($model->wasRecentlyCreated || $model->wasChanged('avatar_url')) {
                ImportRemoteImageJob::dispatchIfRemote($model->getTable(), $model->getKey(), 'avatar_url', 'avatar', $model->avatar_url);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'date_of_birth' => 'immutable_date',
            'email_verified_at' => 'immutable_datetime',
            'phone_verified_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'anonymized_at' => 'immutable_datetime',
            'profile_completed' => 'boolean',
            'is_vendor' => 'boolean',
            'interests_completed' => 'boolean',
            'status' => UserStatus::class,
            'identity_status' => IdentityStatus::class,
        ];
    }

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:sP';
    }

    // ---- Relations ---------------------------------------------------------

    public function privacy(): HasOne
    {
        return $this->hasOne(UserPrivacySetting::class, 'user_id');
    }

    public function notificationPreference(): HasOne
    {
        return $this->hasOne(NotificationPreference::class, 'user_id');
    }

    public function vendorProfile(): HasOne
    {
        return $this->hasOne(VendorProfile::class, 'user_id');
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class, 'user_id');
    }

    public function interests(): BelongsToMany
    {
        return $this->belongsToMany(Interest::class, 'user_interests', 'user_id', 'interest_slug');
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class, 'user_id');
    }

    public function organizedEvents(): HasMany
    {
        return $this->hasMany(Event::class, 'organizer_user_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'user_id');
    }

    public function identityVerifications(): HasMany
    {
        return $this->hasMany(IdentityVerification::class, 'user_id');
    }

    public function deletionRequests(): HasMany
    {
        return $this->hasMany(AccountDeletionRequest::class, 'user_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'user_id');
    }

    // ---- Roles -------------------------------------------------------------

    /** @return list<string> */
    public function roleList(): array
    {
        if (! array_key_exists('role_list', $this->relations)) {
            $roles = DB::table('user_roles')->where('user_id', $this->id)->orderBy('role')->pluck('role')->all();
            $this->setRelation('role_list', collect(array_values(array_unique(['user', ...$roles]))));
        }

        return $this->relations['role_list']->all();
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roleList(), true);
    }

    public function addRole(string $role): void
    {
        DB::table('user_roles')->insertOrIgnore(['user_id' => $this->id, 'role' => $role, 'created_at' => now()]);
        if ($role === 'vendor' && ! $this->is_vendor) {
            $this->forceFill(['is_vendor' => true])->saveQuietly();
        }
        unset($this->relations['role_list']);
    }

    /** @param list<string> $roles */
    public function syncRoles(array $roles): void
    {
        DB::table('user_roles')->where('user_id', $this->id)->delete();
        foreach (array_unique($roles) as $role) {
            DB::table('user_roles')->insert(['user_id' => $this->id, 'role' => $role, 'created_at' => now()]);
        }
        $this->forceFill(['is_vendor' => in_array('vendor', $roles, true)])->saveQuietly();
        unset($this->relations['role_list']);
    }

    // ---- State helpers -----------------------------------------------------

    public function isSuspended(): bool
    {
        return $this->status === UserStatus::Suspended;
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function isIdentityVerified(): bool
    {
        return $this->identity_status === IdentityStatus::Verified;
    }

    public function age(): ?int
    {
        return $this->date_of_birth?->age;
    }

    public function isAdult(): bool
    {
        return ($this->age() ?? 0) >= 18;
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /** The planned next onboarding step, per authentication-and-permissions.md §2. */
    public function nextOnboardingStep(): ?string
    {
        if (! $this->hasVerifiedEmail()) {
            return 'verify_email';
        }
        if (! $this->profile_completed) {
            return 'profile_setup';
        }
        if (! $this->interests_completed) {
            return 'interests';
        }

        return null;
    }

    public function routeNotificationForMail(): ?string
    {
        return $this->anonymized_at ? null : $this->email;
    }
}
