<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\VendorStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vendors\CreateVendorProfileRequest;
use App\Http\Requests\Vendors\PromoRequest;
use App\Http\Requests\Vendors\ReviewRequest;
use App\Http\Requests\Vendors\UpdateVendorProfileRequest;
use App\Http\Requests\Vendors\UpdateWorkRequest;
use App\Http\Requests\Vendors\WorkRequest;
use App\Models\VendorCategory;
use App\Models\VendorProfile;
use App\Models\VendorPromo;
use App\Models\VendorReview;
use App\Models\VendorWork;
use App\Services\Messaging\MessagingService;
use App\Services\Vendors\VendorPresenter;
use App\Services\Vendors\VendorService;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class VendorController extends Controller
{
    public function __construct(private readonly VendorService $vendors, private readonly VendorPresenter $presenter) {}

    public function categories(): JsonResponse
    {
        return $this->ok(VendorCategory::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug])->all());
    }

    public function businessCategories(): JsonResponse
    {
        return $this->ok(VendorCategory::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all());
    }

    public function index(Request $request): JsonResponse
    {
        $page = ListQuery::from($request)->paginate($this->vendors->search($request));

        return response()->json(['data' => $this->presenter->summaries($page->items(), $this->optionalCustomer()), 'meta' => ListQuery::meta($page)]);
    }

    public function featured(): JsonResponse
    {
        $vendors = VendorProfile::query()->where('status', 'active')->orderByDesc('is_featured')->orderByDesc('rating_avg')->orderByDesc('likes_count')->limit(12)->get();

        return $this->ok($this->presenter->summaries($vendors, $this->optionalCustomer()));
    }

    public function show(string $slug): JsonResponse|RedirectResponse
    {
        $vendor = VendorProfile::query()->where('slug', $slug)->orWhere('id', $slug)->first();
        if ($vendor === null && ($moved = $this->movedTo('vendor', $slug, VendorProfile::class))) {
            return $moved;
        }
        $viewer = $this->optionalCustomer();
        if ($vendor === null || ($vendor->status !== VendorStatus::Active && $vendor->user_id !== $viewer?->id)) {
            throw ApiException::notFound('Vendor not found.');
        }

        return $this->ok($this->presenter->detail($vendor, $viewer));
    }

    public function works(Request $request, string $id): JsonResponse
    {
        $vendor = $this->visibleVendor($id);
        $page = ListQuery::from($request)->paginate(VendorWork::query()->where('vendor_profile_id', $vendor->id)->latest(), 12);

        return response()->json(['data' => $this->presenter->works($page->items(), $this->optionalCustomer()), 'meta' => ListQuery::meta($page)]);
    }

    public function work(string $id): JsonResponse
    {
        $work = VendorWork::query()->find($id) ?? throw ApiException::notFound('Work not found.');
        $vendor = $this->visibleVendor($work->vendor_profile_id);
        $viewer = $this->optionalCustomer();

        return $this->ok($this->presenter->works([$work], $viewer)[0] + ['vendor' => $this->presenter->summaries([$vendor], $viewer)[0]]);
    }

    public function like(string $id): JsonResponse
    {
        $work = VendorWork::query()->find($id) ?? throw ApiException::notFound('Work not found.');
        $this->visibleVendor($work->vendor_profile_id);

        return $this->ok($this->vendors->toggleLike($work, $this->customer()));
    }

    public function reviews(string $id): JsonResponse
    {
        $vendor = $this->visibleVendor($id);
        $reviews = VendorReview::query()->with('author')->where('vendor_profile_id', $vendor->id)->latest()->limit(100)->get();

        return $this->ok([
            'items' => $reviews->map(fn ($r) => VendorPresenter::review($r, $this->optionalCustomer()))->all(),
            'summary' => VendorPresenter::ratingSummary($vendor->id),
        ]);
    }

    public function createReview(ReviewRequest $request, string $id): JsonResponse
    {
        $review = $this->vendors->review($this->visibleVendor($id), $this->customer(), (int) $request->input('rating'), $request->string('comment'));

        return $this->created(VendorPresenter::review($review->load('author'), $this->customer()), 'Thanks for your review.');
    }

    public function updateReview(ReviewRequest $request, string $id, string $reviewId): JsonResponse
    {
        $review = $this->ownReview($id, $reviewId);

        return $this->ok(VendorPresenter::review($this->vendors->updateReview($review, (int) $request->input('rating'), $request->string('comment'))->load('author'), $this->customer()));
    }

    public function deleteReview(string $id, string $reviewId): Response
    {
        $this->vendors->deleteReview($this->ownReview($id, $reviewId));

        return $this->noContent();
    }

    public function save(string $id): JsonResponse
    {
        $vendor = $this->visibleVendor($id);
        DB::table('saved_vendors')->insertOrIgnore(['user_id' => $this->customer()->id, 'vendor_profile_id' => $vendor->id, 'created_at' => now()]);

        return $this->ok(['saved' => true]);
    }

    public function unsave(string $id): Response
    {
        DB::table('saved_vendors')->where('user_id', $this->customer()->id)->where('vendor_profile_id', $id)->delete();

        return $this->noContent();
    }

    public function saved(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);
        $query = VendorProfile::query()->select('vendor_profiles.*')
            ->join('saved_vendors', 'saved_vendors.vendor_profile_id', '=', 'vendor_profiles.id')
            ->where('saved_vendors.user_id', $this->customer()->id)->where('vendor_profiles.status', 'active')
            ->when($list->searchPattern(), fn ($q, $p) => $q->where('vendor_profiles.business_name', 'ilike', $p))
            ->orderByDesc('saved_vendors.created_at');
        $page = $list->paginate($query);

        return response()->json(['data' => $this->presenter->summaries($page->items(), $this->customer()), 'meta' => ListQuery::meta($page)]);
    }

    public function contact(string $id, MessagingService $messaging): JsonResponse
    {
        $vendor = $this->visibleVendor($id);
        if ($vendor->user_id === $this->customer()->id) {
            throw ApiException::validation('This is your own business.', ['vendorId' => ['You cannot message your own business.']]);
        }
        $conversation = $messaging->startDirect($this->customer(), $vendor->user_id);

        return $this->ok(['conversationId' => $conversation->id]);
    }

    // ---- My business profile -------------------------------------------------------

    public function myProfile(): JsonResponse
    {
        $vendor = VendorProfile::query()->where('user_id', $this->customer()->id)->first();

        return $this->ok($vendor ? $this->presenter->detail($vendor, $this->customer()) : null);
    }

    public function createProfile(CreateVendorProfileRequest $request): JsonResponse
    {
        $vendor = $this->vendors->create($this->customer(), $request->validated());

        return $this->created($this->presenter->detail($vendor, $this->customer()), 'Business profile submitted for verification.');
    }

    public function updateProfile(UpdateVendorProfileRequest $request): JsonResponse
    {
        $vendor = $this->vendors->update($this->ownProfile(), $this->customer(), $request->validated());

        return $this->ok($this->presenter->detail($vendor, $this->customer()), 'Business profile saved.');
    }

    public function deleteProfile(): Response
    {
        $profile = $this->ownProfile();
        $active = DB::table('event_vendor_assignments')->where('vendor_profile_id', $profile->id)->where('status', 'accepted')
            ->whereIn('event_id', DB::table('events')->whereIn('status', ['live', 'pending_review'])->select('id'))->exists();
        if ($active) {
            throw ApiException::conflict('You are assigned to upcoming events. Ask the organisers to remove you first.');
        }
        $profile->delete();

        return $this->noContent();
    }

    public function createWork(WorkRequest $request): JsonResponse
    {
        $work = $this->vendors->createWork($this->ownProfile(), $this->customer(), $request->string('title'), $request->string('description'), $request->validated('mediaUploadIds'));

        return $this->created($this->presenter->works([$work], $this->customer())[0]);
    }

    public function updateWork(UpdateWorkRequest $request, string $id): JsonResponse
    {
        $work = VendorWork::query()->where('vendor_profile_id', $this->ownProfile()->id)->findOrFail($id);

        return $this->ok($this->presenter->works([$this->vendors->updateWork($work, $this->customer(), $request->validated())], $this->customer())[0]);
    }

    public function deleteWork(string $id): Response
    {
        $this->vendors->deleteWork(VendorWork::query()->where('vendor_profile_id', $this->ownProfile()->id)->findOrFail($id));

        return $this->noContent();
    }

    public function createPromo(PromoRequest $request): JsonResponse
    {
        return $this->created(VendorPresenter::promo($this->vendors->createPromo($this->ownProfile(), $request->validated())));
    }

    public function deletePromo(string $id): Response
    {
        VendorPromo::query()->where('vendor_profile_id', $this->ownProfile()->id)->findOrFail($id)->delete();

        return $this->noContent();
    }

    private function visibleVendor(string $id): VendorProfile
    {
        $vendor = VendorProfile::query()->find($id);
        $viewer = $this->optionalCustomer();
        if ($vendor === null || ($vendor->status !== VendorStatus::Active && $vendor->user_id !== $viewer?->id)) {
            throw ApiException::notFound('Vendor not found.');
        }

        return $vendor;
    }

    private function ownProfile(): VendorProfile
    {
        return VendorProfile::query()->where('user_id', $this->customer()->id)->first() ?? throw ApiException::notFound('You do not have a business profile.');
    }

    private function ownReview(string $vendorId, string $reviewId): VendorReview
    {
        return VendorReview::query()->where('vendor_profile_id', $vendorId)->where('user_id', $this->customer()->id)->find($reviewId)
            ?? throw ApiException::notFound('Review not found.');
    }
}
