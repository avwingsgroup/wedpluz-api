<?php

namespace App\Services\Vendors;

use App\Enums\VendorStatus;
use App\Exceptions\ApiException;
use App\Models\Media;
use App\Models\User;
use App\Models\VendorProfile;
use App\Models\VendorPromo;
use App\Models\VendorReview;
use App\Models\VendorWork;
use App\Services\Media\UploadService;
use App\Services\Platform\SlugHistory;
use App\Support\Codes;
use App\Support\ListQuery;
use App\Support\Sql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Vendor marketplace and business profiles (business-rules.md, "Vendors and
 * reviews"). One profile per user; new profiles are pending_verification and
 * appear in search once an admin verifies them. One review per user per
 * vendor, never on one's own profile; rating and count are recomputed on change.
 */
class VendorService
{
    public function __construct(private readonly UploadService $uploads) {}

    public function search(Request $request): Builder
    {
        $list = ListQuery::from($request);
        $query = VendorProfile::query()->where('status', VendorStatus::Active->value);

        if ($search = $list->search()) {
            $pattern = $list->searchPattern();
            $query->where(fn ($q) => $q->where('business_name', 'ilike', $pattern)->orWhere('description', 'ilike', $pattern)
                ->orWhereHas('category', fn ($c) => $c->where('name', 'ilike', $pattern)));
        }
        if ($location = trim((string) $request->query('location', ''))) {
            $query->where('location', 'ilike', '%'.addcslashes($location, '%_\\').'%');
        }
        if ($slug = $list->filter('categorySlug')) {
            $query->whereHas('category', fn ($c) => $c->where('slug', $slug));
        }
        if (($min = $request->query('minRating')) !== null && is_numeric($min)) {
            $query->where('rating_avg', '>=', (float) $min);
        }
        if ($availability = $list->filter('availability')) {
            $query->where('availability', $availability);
        }

        $sort = (string) $request->query('sort', 'relevance');
        match ($sort) {
            'rating' => $query->orderByDesc('rating_avg')->orderByDesc('ratings_count'),
            'newest' => $query->orderByDesc('created_at'),
            'relevance' => $search
                ? $query->orderByRaw('similarity(business_name, ?) desc', [$search])->orderByDesc('rating_avg')
                : $query->orderByDesc('is_featured')->orderByDesc('rating_avg')->orderByDesc('ratings_count'),
            default => throw ApiException::validation('Unknown sort field.', ['sort' => ['The selected sort is invalid.']]),
        };

        return $query;
    }

    /** @param array<string,mixed> $data */
    public function create(User $user, array $data): VendorProfile
    {
        if (VendorProfile::query()->where('user_id', $user->id)->exists()) {
            throw ApiException::conflict('You already have a business profile.');
        }

        return DB::transaction(function () use ($user, $data) {
            $profile = VendorProfile::query()->create([
                'user_id' => $user->id,
                'business_name' => $data['businessName'],
                'headline' => isset($data['headline']) ? (trim((string) $data['headline']) ?: null) : null,
                'slug' => $this->slug($data['businessName']),
                'category_id' => $data['categoryId'],
                'location' => $data['location'],
                'description' => $data['description'],
                'avatar_url' => ! empty($data['avatarUploadId']) ? $this->uploads->consume($data['avatarUploadId'], $user, ['avatar', 'cover'], 'avatarUploadId')->url : null,
                'cover_url' => ! empty($data['coverUploadId']) ? $this->uploads->consume($data['coverUploadId'], $user, ['cover', 'avatar'], 'coverUploadId')->url : null,
                'contact_email' => isset($data['contactEmail']) ? mb_strtolower($data['contactEmail']) : $user->email,
                'contact_phone' => $data['contactPhone'] ?? $user->phone,
                'website' => $data['website'] ?? null,
                'socials' => $data['socials'] ?? null,
                'status' => VendorStatus::PendingVerification,
                'availability' => 'available',
            ]);
            $user->addRole('vendor');

            return $profile;
        });
    }

    /** @param array<string,mixed> $data */
    public function update(VendorProfile $profile, User $user, array $data): VendorProfile
    {
        $map = ['location' => 'location', 'categoryId' => 'category_id', 'description' => 'description', 'contactPhone' => 'contact_phone', 'website' => 'website', 'availability' => 'availability', 'socials' => 'socials'];
        $changes = [];
        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $changes[$column] = $data[$key];
            }
        }
        if (array_key_exists('headline', $data)) {
            $changes['headline'] = trim((string) $data['headline']) ?: null;
        }
        if (isset($data['businessName']) && $data['businessName'] !== $profile->business_name) {
            $changes['business_name'] = $data['businessName'];
        }
        if (array_key_exists('contactEmail', $data)) {
            $changes['contact_email'] = $data['contactEmail'] ? mb_strtolower($data['contactEmail']) : null;
            if ($changes['contact_email'] !== $profile->contact_email) {
                $changes['contact_email_verified_at'] = null;
            }
        }
        if (! empty($data['avatarUploadId'])) {
            $changes['avatar_url'] = $this->uploads->consume($data['avatarUploadId'], $user, ['avatar', 'cover'], 'avatarUploadId')->url;
        } elseif (array_key_exists('avatarUploadId', $data) && $data['avatarUploadId'] === null) {
            $changes['avatar_url'] = null;
        }
        if (! empty($data['coverUploadId'])) {
            $changes['cover_url'] = $this->uploads->consume($data['coverUploadId'], $user, ['cover', 'avatar'], 'coverUploadId')->url;
        } elseif (array_key_exists('coverUploadId', $data) && $data['coverUploadId'] === null) {
            $changes['cover_url'] = null;
        }
        // A rejected profile goes back to review when edited.
        if ($profile->status === VendorStatus::Rejected) {
            $changes['status'] = VendorStatus::PendingVerification;
            $changes['rejection_reason'] = null;
        }
        $renamed = isset($changes['business_name']);
        $profile->forceFill($changes)->save();
        if ($renamed) {
            // The URL follows the business name; links to a verified profile keep working via a redirect.
            app(SlugHistory::class)->change('vendor', $profile, $this->slug($profile->business_name), rememberOld: $profile->verified_at !== null);
        }

        return $profile->refresh();
    }

    /** @param list<string> $mediaUploadIds */
    public function createWork(VendorProfile $profile, User $user, string $title, string $description, array $mediaUploadIds): VendorWork
    {
        return DB::transaction(function () use ($profile, $user, $title, $description, $mediaUploadIds) {
            $work = VendorWork::query()->create(['vendor_profile_id' => $profile->id, 'title' => $title, 'description' => $description]);
            $this->attachMedia($work, $user, $mediaUploadIds);
            $profile->increment('works_count');

            return $work->load('media');
        });
    }

    /** @param array{title?:string, description?:string, mediaUploadIds?:list<string>} $data */
    public function updateWork(VendorWork $work, User $user, array $data): VendorWork
    {
        return DB::transaction(function () use ($work, $user, $data) {
            $work->forceFill(array_filter(['title' => $data['title'] ?? null, 'description' => $data['description'] ?? null], fn ($v) => $v !== null))->save();
            if (isset($data['mediaUploadIds'])) {
                Media::query()->where('owner_type', 'work')->where('owner_id', $work->id)->delete();
                $this->attachMedia($work, $user, $data['mediaUploadIds']);
            }

            return $work->load('media');
        });
    }

    public function deleteWork(VendorWork $work): void
    {
        DB::transaction(function () use ($work) {
            $work->delete();
            VendorProfile::query()->whereKey($work->vendor_profile_id)->where('works_count', '>', 0)->decrement('works_count');
        });
    }

    /** @return array{liked:bool, likesCount:int} */
    public function toggleLike(VendorWork $work, User $user): array
    {
        return DB::transaction(function () use ($work, $user) {
            $deleted = DB::table('work_likes')->where('user_id', $user->id)->where('work_id', $work->id)->delete();
            if ($deleted) {
                VendorWork::query()->whereKey($work->id)->where('likes_count', '>', 0)->decrement('likes_count');
                VendorProfile::query()->whereKey($work->vendor_profile_id)->where('likes_count', '>', 0)->decrement('likes_count');
            } else {
                DB::table('work_likes')->insert(['user_id' => $user->id, 'work_id' => $work->id, 'created_at' => now()]);
                VendorWork::query()->whereKey($work->id)->increment('likes_count');
                VendorProfile::query()->whereKey($work->vendor_profile_id)->increment('likes_count');
            }

            return ['liked' => ! $deleted, 'likesCount' => (int) VendorWork::query()->whereKey($work->id)->value('likes_count')];
        });
    }

    public function review(VendorProfile $vendor, User $user, int $rating, string $comment): VendorReview
    {
        if ($vendor->user_id === $user->id) {
            throw ApiException::validation('You cannot review your own business.', ['rating' => ['You cannot review your own business.']]);
        }
        $review = Sql::insertOrNull(fn () => VendorReview::query()->create([
            'vendor_profile_id' => $vendor->id, 'user_id' => $user->id, 'rating' => $rating, 'comment' => $comment,
        ]));
        if ($review === null) {
            throw ApiException::conflict('You have already reviewed this vendor. Edit your review instead.');
        }
        $this->recomputeRating($vendor->id);

        return $review;
    }

    public function updateReview(VendorReview $review, int $rating, string $comment): VendorReview
    {
        $review->forceFill(['rating' => $rating, 'comment' => $comment])->save();
        $this->recomputeRating($review->vendor_profile_id);

        return $review;
    }

    public function deleteReview(VendorReview $review): void
    {
        $review->delete();
        $this->recomputeRating($review->vendor_profile_id);
    }

    public function createPromo(VendorProfile $profile, array $data): VendorPromo
    {
        return VendorPromo::query()->create([
            'vendor_profile_id' => $profile->id,
            'title' => $data['title'],
            'description' => $data['description'],
            'discount_percent' => $data['discountPercent'] ?? null,
            'valid_until' => $data['validUntil'] ?? null,
        ]);
    }

    public function recomputeRating(string $vendorId): void
    {
        $row = DB::table('vendor_reviews')->where('vendor_profile_id', $vendorId)->whereNull('deleted_at')
            ->selectRaw('coalesce(avg(rating), 0) as avg, count(*) as n')->first();
        VendorProfile::query()->whereKey($vendorId)->update(['rating_avg' => round((float) $row->avg, 2), 'ratings_count' => (int) $row->n]);
    }

    /** @param list<string> $uploadIds */
    private function attachMedia(VendorWork $work, User $user, array $uploadIds): void
    {
        foreach (array_values($uploadIds) as $i => $uploadId) {
            $upload = $this->uploads->consume($uploadId, $user, 'vendor_work', "mediaUploadIds.$i");
            Media::query()->create([
                'owner_type' => 'work',
                'owner_id' => $work->id,
                'upload_id' => $upload->id,
                'type' => str_starts_with($upload->mime_type, 'video/') ? 'video' : ($upload->mime_type === 'application/pdf' ? 'pdf' : 'image'),
                'url' => (string) $upload->url,
                'thumbnail_url' => $upload->thumbnail_url,
                'width' => $upload->width,
                'height' => $upload->height,
                'duration_seconds' => $upload->duration_seconds,
                'size_bytes' => $upload->size_bytes,
                'provider' => $upload->provider,
                'provider_public_id' => $upload->provider_public_id,
                'sort_order' => $i,
            ]);
        }
    }

    private function slug(string $name): string
    {
        $base = Str::slug(Str::limit($name, 80, '')) ?: 'vendor';
        $slug = $base;
        while (VendorProfile::withTrashed()->where('slug', $slug)->exists() || app(SlugHistory::class)->reserved('vendor', $slug)) {
            $slug = $base.'-'.strtolower(Codes::random(4));
        }

        return $slug;
    }
}
