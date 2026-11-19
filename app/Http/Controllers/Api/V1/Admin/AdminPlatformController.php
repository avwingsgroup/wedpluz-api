<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\PlatformController;
use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\CmsPage;
use App\Services\Admin\AdminPresenter;
use App\Services\Admin\AuditLogger;
use App\Services\Platform\SettingsService;
use App\Support\RichText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Platform settings (no secrets), banners and CMS pages (with version history). */
class AdminPlatformController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function settings(SettingsService $settings): JsonResponse
    {
        return $this->ok($settings->all());
    }

    public function updateSettings(Request $request, SettingsService $settings): JsonResponse
    {
        $data = $request->validate([
            'general' => ['sometimes', 'array'],
            'general.platformName' => ['sometimes', 'string', 'max:100'], 'general.supportEmail' => ['sometimes', 'email'],
            'general.supportPhone' => ['sometimes', 'string', 'max:30'], 'general.supportWhatsapp' => ['sometimes', 'string', 'max:30'],
            'general.defaultCurrency' => ['sometimes', 'in:NGN,USD,GBP,EUR'], 'general.defaultCountry' => ['sometimes', 'size:2'],
            'general.timezone' => ['sometimes', 'timezone'], 'general.maintenanceMode' => ['sometimes', 'boolean'],
            'payments' => ['sometimes', 'array'],
            'payments.enabledProviders' => ['sometimes', 'array'], 'payments.enabledProviders.*' => ['in:paystack,stripe,paypal,monnify,flutterwave,wallet'],
            'payments.defaultProvider' => ['sometimes', 'in:paystack,stripe,paypal,monnify,flutterwave'],
            'payments.serviceFeePercent' => ['sometimes', 'numeric', 'min:0', 'max:30'], 'payments.serviceFeeFixed' => ['sometimes', 'integer', 'min:0'],
            'payments.passFeesToBuyer' => ['sometimes', 'boolean'], 'payments.refundWindowHours' => ['sometimes', 'integer', 'min:0', 'max:8760'],
            'payments.settlementDelayDays' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'payments.minWithdrawal' => ['sometimes', 'integer', 'min:0'], 'payments.maxWithdrawal' => ['sometimes', 'integer', 'min:0'],
            'features' => ['sometimes', 'array'], 'features.*' => ['boolean'],
            'app' => ['sometimes', 'array'], 'app.minAndroidVersion' => ['sometimes', 'string', 'max:20'], 'app.minIosVersion' => ['sometimes', 'string', 'max:20'],
            'app.forceUpdate' => ['sometimes', 'boolean'], 'app.androidStoreUrl' => ['sometimes', 'url'], 'app.iosStoreUrl' => ['sometimes', 'url'],
            'legal' => ['sometimes', 'array'], 'legal.termsUrl' => ['sometimes', 'url'], 'legal.privacyUrl' => ['sometimes', 'url'],
            'legal.termsUpdatedAt' => ['sometimes', 'date'], 'legal.privacyUpdatedAt' => ['sometimes', 'date'],
        ]);
        $before = $settings->all();
        $after = $settings->update($data, $this->admin()->id);
        $this->audit->log('settings.updated', 'settings', null, implode(', ', array_keys($data)), array_intersect_key($before, $data), array_intersect_key($after, $data));

        return $this->ok($after, 'Settings saved.');
    }

    public function banners(): JsonResponse
    {
        return $this->ok(Banner::query()->orderByDesc('priority')->orderByDesc('created_at')->get()->map(fn ($b) => AdminPresenter::banner($b))->all());
    }

    public function createBanner(Request $request): JsonResponse
    {
        $banner = Banner::query()->create($this->bannerData($request, true));
        $this->audit->log('banner.created', 'settings', $banner, $banner->title);

        return $this->created(AdminPresenter::banner($banner->refresh()));
    }

    public function updateBanner(Request $request, string $id): JsonResponse
    {
        $banner = Banner::query()->findOrFail($id);
        $before = AdminPresenter::banner($banner);
        $banner->forceFill($this->bannerData($request, false))->save();
        $this->audit->log('banner.updated', 'settings', $banner, $banner->title, $before, AdminPresenter::banner($banner));

        return $this->ok(AdminPresenter::banner($banner));
    }

    public function deleteBanner(string $id): Response
    {
        $banner = Banner::query()->findOrFail($id);
        $banner->delete();
        $this->audit->log('banner.deleted', 'settings', $banner, $banner->title);

        return $this->noContent();
    }

    public function pages(): JsonResponse
    {
        return $this->ok(CmsPage::query()->with('updatedBy')->orderBy('slug')->get()->map(fn ($p) => PlatformController::pageResource($p))->all());
    }

    public function updatePage(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate(['title' => ['sometimes', 'string', 'max:200'], 'content' => ['sometimes', 'string', 'max:200000']]);
        abort_unless(in_array($slug, ['about', 'terms', 'privacy', 'faqs', 'help', 'contact'], true), 404);
        $page = CmsPage::query()->firstOrCreate(['slug' => $slug], ['title' => ucfirst($slug), 'content' => '']);

        DB::transaction(function () use ($page, $data) {
            DB::table('cms_page_versions')->insert(['cms_page_id' => $page->id, 'title' => $page->title, 'content' => $page->content, 'updated_by_admin_id' => $page->updated_by_admin_id, 'created_at' => now()]);
            $page->forceFill([
                'title' => $data['title'] ?? $page->title,
                'content' => isset($data['content']) ? RichText::sanitize($data['content']) : $page->content,
                'updated_by_admin_id' => $this->admin()->id,
            ])->save();
        });
        $this->audit->log('page.updated', 'settings', $page, $page->slug, null, ['title' => $page->title]);

        return $this->ok(PlatformController::pageResource($page->load('updatedBy')));
    }

    private function bannerData(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'title' => [$req, 'string', 'max:200'], 'subtitle' => ['sometimes', 'nullable', 'string', 'max:500'],
            'imageUrl' => ['sometimes', 'nullable', 'url', 'max:1024'], 'ctaLabel' => ['sometimes', 'nullable', 'string', 'max:60'],
            'ctaUrl' => ['sometimes', 'nullable', 'url', 'max:1024'],
            'placement' => ['sometimes', 'in:home_hero,home_inline,events_top,vendors_top,app_home'],
            'audience' => ['sometimes', 'in:all,individuals,businesses,guests'],
            'startsAt' => ['sometimes', 'nullable', 'date'], 'endsAt' => ['sometimes', 'nullable', 'date', 'after_or_equal:startsAt'],
            'isActive' => ['sometimes', 'boolean'], 'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);
        $map = ['title' => 'title', 'subtitle' => 'subtitle', 'imageUrl' => 'image_url', 'ctaLabel' => 'cta_label', 'ctaUrl' => 'cta_url', 'placement' => 'placement',
            'audience' => 'audience', 'startsAt' => 'starts_at', 'endsAt' => 'ends_at', 'isActive' => 'is_active', 'priority' => 'priority'];
        $out = [];
        foreach ($map as $k => $col) {
            if (array_key_exists($k, $data)) {
                $out[$col] = $data[$k];
            }
        }

        return $out;
    }
}
