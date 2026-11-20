<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ReportRequest;
use App\Http\Requests\Account\SupportTicketRequest;
use App\Http\Requests\Account\UploadRequest;
use App\Http\Requests\Account\UploadSignRequest;
use App\Http\Resources\Fmt;
use App\Models\Banner;
use App\Models\CmsPage;
use App\Models\Interest;
use App\Models\SupportTicket;
use App\Models\Upload;
use App\Models\User;
use App\Services\Media\UploadService;
use App\Services\Moderation\ReportService;
use App\Services\Platform\SettingsService;
use App\Services\Support\SupportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Uploads, public profiles, CMS pages, interests, config, support tickets and reports. */
class PlatformController extends Controller
{
    public function upload(UploadRequest $request, UploadService $uploads): JsonResponse
    {
        return $this->created(self::uploadResource($uploads->store($request->file('file'), $request->string('purpose'), $this->customer())));
    }

    public function signUpload(UploadSignRequest $request, UploadService $uploads): JsonResponse
    {
        return $this->created($uploads->signature($request->string('purpose'), $this->customer()));
    }

    public function interests(): JsonResponse
    {
        return $this->ok(Interest::query()->where('is_active', true)->orderBy('sort_order')->orderBy('label')
            ->get()->map(fn (Interest $i) => ['label' => $i->label, 'slug' => $i->slug])->all());
    }

    /**
     * GET /config. `geo.countryCode` is the visitor's country from the edge (Cloudflare's
     * CF-IPCountry by default, GEO_COUNTRY_HEADER to change), or null when unknown. It only
     * pre-selects the phone country picker, so a spoofed header is harmless.
     */
    public function config(Request $request, SettingsService $settings): JsonResponse
    {
        $header = (string) config('wedpluz.geo.country_header', 'CF-IPCountry');
        $country = strtoupper(trim((string) $request->header($header, '')));
        $valid = preg_match('/^[A-Z]{2}$/', $country) && ! in_array($country, ['XX', 'T1'], true); // XX unknown, T1 Tor

        return $this->ok($settings->publicConfig() + ['geo' => ['countryCode' => $valid ? $country : null]])
            ->header('Vary', $header); // the response differs per visitor country, so shared caches must key on it
    }

    /**
     * GET /banners?placement=&audience= — banners live right now, highest
     * priority first (public, cached 5 minutes). `placement=onboarding` is the
     * sign-up/login carousel. Images are delivered through Cloudinary.
     */
    public function banners(Request $request): JsonResponse
    {
        $data = $request->validate([
            'placement' => ['nullable', 'string', 'in:'.implode(',', Banner::PLACEMENTS)],
            'audience' => ['nullable', 'string', 'in:all,individuals,businesses,guests'],
        ]);
        $banners = Banner::query()->live()
            ->when($data['placement'] ?? null, fn ($q, $p) => $q->where('placement', $p))
            ->when($data['audience'] ?? null, fn ($q, $a) => $q->whereIn('audience', ['all', $a]))
            ->orderByDesc('priority')->orderBy('created_at')->limit(20)->get();

        return $this->ok($banners->map(fn (Banner $b) => [
            'id' => $b->id, 'title' => $b->title, 'subtitle' => $b->subtitle, 'imageUrl' => $b->image_url,
            'ctaLabel' => $b->cta_label, 'ctaUrl' => $b->cta_url, 'placement' => $b->placement, 'audience' => $b->audience,
            'priority' => $b->priority,
        ])->values()->all());
    }

    public function page(string $slug): JsonResponse
    {
        $page = CmsPage::query()->with('updatedBy')->where('slug', $slug)->firstOrFail();

        return $this->ok(self::pageResource($page));
    }

    /** GET /users/{id} — public profile, respecting privacy settings. */
    public function publicProfile(string $id): JsonResponse
    {
        $user = User::query()->whereKey($id)->whereNull('anonymized_at')->whereIn('status', ['active', 'suspended'])->first();
        if ($user === null) {
            throw ApiException::notFound('User not found.');
        }
        $viewer = $this->optionalCustomer();
        $privacy = DB::table('user_privacy_settings')->where('user_id', $user->id)->first();
        $visible = $viewer?->id === $user->id || ($privacy->profile_visibility ?? 'public') === 'public';
        if ($viewer && DB::table('blocks')->where('blocker_user_id', $user->id)->where('blocked_user_id', $viewer->id)->exists()) {
            throw ApiException::notFound('User not found.');
        }

        return $this->ok(Fmt::user($user) + [
            'city' => $visible ? $user->city : null,
            'interests' => $visible ? DB::table('user_interests')->where('user_id', $user->id)->pluck('interest_slug')->all() : [],
            'joinedAt' => Fmt::date($user->created_at),
            'eventsCount' => DB::table('events')->where('organizer_user_id', $user->id)->whereNull('deleted_at')
                ->whereIn('status', ['live', 'completed'])->where('visibility', 'public')->count(),
        ]);
    }

    public function openTicket(SupportTicketRequest $request, SupportService $support): JsonResponse
    {
        $ticket = $support->open($this->customer(), $request->string('subject'), $request->string('category'), $request->string('message'));

        return $this->created(SupportService::resource($ticket, 1), 'Support ticket opened. We will reply by email.');
    }

    public function tickets(): JsonResponse
    {
        $tickets = SupportTicket::query()->with(['user', 'assignee'])->withCount('messages')
            ->where('user_id', $this->customer()->id)->latest()->limit(100)->get();

        return $this->ok($tickets->map(fn (SupportTicket $t) => SupportService::resource($t, $t->messages_count))->all());
    }

    public function report(ReportRequest $request, ReportService $reports): JsonResponse
    {
        $report = $reports->report($this->customer(), $request->validated());

        return $this->ok(['id' => $report->id, 'status' => $report->status], 'Thanks for your report. Our team will review it.');
    }

    public static function uploadResource(Upload $u): array
    {
        return [
            'id' => $u->id,
            'url' => (string) ($u->url ?? ''),
            'thumbnailUrl' => $u->thumbnail_url,
            'mimeType' => $u->mime_type,
            'sizeBytes' => (int) $u->size_bytes,
            'width' => $u->width,
            'height' => $u->height,
            'provider' => $u->provider,
        ];
    }

    public static function pageResource(CmsPage $p): array
    {
        return [
            'id' => $p->id,
            'slug' => $p->slug,
            'title' => $p->title,
            'content' => $p->content,
            'updatedAt' => Fmt::date($p->updated_at),
            'updatedBy' => $p->updatedBy?->fullName(),
        ];
    }
}
