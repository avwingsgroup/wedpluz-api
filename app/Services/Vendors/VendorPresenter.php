<?php

namespace App\Services\Vendors;

use App\Http\Resources\Fmt;
use App\Models\Media;
use App\Models\User;
use App\Models\VendorProfile;
use App\Models\VendorPromo;
use App\Models\VendorReview;
use App\Models\VendorWork;
use App\Support\Models;
use Illuminate\Support\Facades\DB;

/** VendorSummary, Vendor, VendorWork, VendorReview and MediaItem shapes (batched). */
class VendorPresenter
{
    /** @param iterable<VendorProfile> $vendors */
    public function summaries(iterable $vendors, ?User $viewer): array
    {
        $vendors = Models::collect($vendors);
        if ($vendors->isEmpty()) {
            return [];
        }
        $vendors = Models::collect($vendors);
        $vendors->loadMissing('category');
        $saved = $viewer ? DB::table('saved_vendors')->where('user_id', $viewer->id)->whereIn('vendor_profile_id', $vendors->pluck('id'))->pluck('vendor_profile_id')->flip()->all() : [];
        $featured = $this->featuredMedia($vendors->pluck('id')->all());

        return $vendors->map(fn (VendorProfile $v) => $this->summary($v, isset($saved[$v->id]), $featured[$v->id] ?? []))->values()->all();
    }

    public function detail(VendorProfile $v, ?User $viewer): array
    {
        $summary = $this->summaries([$v], $viewer)[0];
        $reviews = VendorReview::query()->with('author')->where('vendor_profile_id', $v->id)->latest()->limit(5)->get();
        $promos = VendorPromo::query()->where('vendor_profile_id', $v->id)->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))->latest()->get();

        return $summary + [
            'coverUrl' => $v->cover_url,
            'description' => (string) $v->description,
            'contactEmail' => $v->contact_email,
            'contactPhone' => $v->contact_phone,
            'website' => $v->website,
            'socials' => (object) ($v->socials ?? []),
            'status' => $v->status->value,
            'worksCount' => $v->works_count,
            'reviews' => $reviews->map(fn ($r) => self::review($r, $viewer))->all(),
            'promos' => $promos->map(fn ($p) => self::promo($p))->all(),
            'joinedAt' => Fmt::date($v->created_at),
            'isMine' => $viewer?->id === $v->user_id,
        ];
    }

    private function summary(VendorProfile $v, bool $saved, array $media): array
    {
        return [
            'id' => $v->id,
            'userId' => $v->user_id,
            'businessName' => $v->business_name,
            // Short specialty for cards ("Bridal wear rentals"); null = show the category name.
            'headline' => $v->headline,
            'slug' => $v->slug,
            'category' => $v->category ? ['id' => $v->category->id, 'name' => $v->category->name, 'slug' => $v->category->slug] : ['id' => '', 'name' => 'Other', 'slug' => 'other'],
            'location' => $v->location,
            'avatarUrl' => $v->avatar_url,
            'coverUrl' => $v->cover_url,
            'rating' => round((float) $v->rating_avg, 1),
            'ratingsCount' => $v->ratings_count,
            'likesCount' => $v->likes_count,
            'isVerified' => $v->verified_at !== null && $v->status->value === 'active',
            'isSaved' => $saved,
            'featuredWorks' => $media,
            'availability' => $v->availability,
        ];
    }

    /** @param iterable<VendorWork> $works */
    public function works(iterable $works, ?User $viewer): array
    {
        $works = Models::collect($works);
        $works->loadMissing('media');
        $liked = $viewer ? DB::table('work_likes')->where('user_id', $viewer->id)->whereIn('work_id', $works->pluck('id'))->pluck('work_id')->flip()->all() : [];

        return $works->map(fn (VendorWork $w) => [
            'id' => $w->id,
            'vendorId' => $w->vendor_profile_id,
            'title' => $w->title,
            'description' => (string) $w->description,
            'media' => $w->media->map(fn ($m) => self::media($m))->all(),
            'likesCount' => $w->likes_count,
            'isLiked' => isset($liked[$w->id]),
            'createdAt' => Fmt::date($w->created_at),
        ])->values()->all();
    }

    public static function media(Media $m): array
    {
        return [
            'id' => $m->id,
            'type' => in_array($m->type, ['image', 'video', 'pdf'], true) ? $m->type : 'image',
            'url' => $m->url,
            'thumbnailUrl' => $m->thumbnail_url,
            'width' => $m->width,
            'height' => $m->height,
            'durationSeconds' => $m->duration_seconds,
        ];
    }

    public static function review(VendorReview $r, ?User $viewer): array
    {
        return [
            'id' => $r->id,
            'author' => Fmt::user($r->author),
            'rating' => $r->rating,
            'comment' => $r->comment,
            'createdAt' => Fmt::date($r->created_at),
            'updatedAt' => $r->updated_at && $r->updated_at->ne($r->created_at) ? Fmt::date($r->updated_at) : null,
            'isMine' => $viewer?->id === $r->user_id,
        ];
    }

    public static function promo(VendorPromo $p): array
    {
        return [
            'id' => $p->id,
            'title' => $p->title,
            'description' => $p->description,
            'discountPercent' => $p->discount_percent,
            'validUntil' => Fmt::date($p->valid_until),
            'coverUrl' => $p->cover_url,
        ];
    }

    /** First four media items across each vendor's latest works. @return array<string, list<array>> */
    private function featuredMedia(array $vendorIds): array
    {
        $rows = Media::query()->select('media.*', 'vendor_works.vendor_profile_id')
            ->join('vendor_works', fn ($j) => $j->on('vendor_works.id', '=', 'media.owner_id')->where('media.owner_type', 'work'))
            ->whereIn('vendor_works.vendor_profile_id', $vendorIds)->whereNull('vendor_works.deleted_at')
            ->orderByDesc('vendor_works.created_at')->orderBy('media.sort_order')->limit(count($vendorIds) * 12)->get();

        $out = [];
        foreach ($rows as $m) {
            $vid = $m->getAttribute('vendor_profile_id');
            if (count($out[$vid] ?? []) < 4) {
                $out[$vid][] = self::media($m);
            }
        }

        return $out;
    }

    /** ReviewsResponse summary. */
    public static function ratingSummary(string $vendorId): array
    {
        $dist = DB::table('vendor_reviews')->where('vendor_profile_id', $vendorId)->whereNull('deleted_at')
            ->selectRaw('rating, count(*) as c')->groupBy('rating')->pluck('c', 'rating');
        $total = (int) $dist->sum();
        $avg = $total > 0 ? round($dist->map(fn ($c, $r) => $c * $r)->sum() / $total, 1) : 0;

        return [
            'average' => $avg,
            'total' => $total,
            'distribution' => collect([5, 4, 3, 2, 1])->map(fn ($s) => ['stars' => $s, 'count' => (int) ($dist[$s] ?? 0)])->all(),
        ];
    }
}
