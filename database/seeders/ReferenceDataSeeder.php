<?php

namespace Database\Seeders;

use App\Enums\AdminPermission;
use App\Integrations\Storage\CloudinaryClient;
use App\Models\AdminRole;
use App\Models\Banner;
use App\Models\CmsPage;
use App\Models\CommissionRule;
use App\Models\EventCategory;
use App\Models\Interest;
use App\Models\NotificationTemplate;
use App\Models\Plan;
use App\Models\VendorCategory;
use App\Services\Notifications\DefaultTemplates;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Production-safe reference data. Idempotent: re-running updates names but
 * never duplicates rows or overwrites admin-edited content (CMS pages and
 * notification templates are only created when missing).
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->eventCategories();
        $this->vendorCategories();
        $this->interests();
        $this->plans();
        $this->cmsPages();
        $this->templates();
        $this->roles();
        $this->commission();
        $this->onboardingSlides();
    }

    /**
     * The sign-up / login image carousel (designs: onboarding/*.png), served as
     * banners with placement `onboarding` (GET /banners?placement=onboarding).
     * Images are the design's own photos with the caption removed, kept in
     * database/seeders/assets/onboarding and uploaded to this environment's
     * Cloudinary (or public/images when Cloudinary is not configured).
     * firstOrCreate: admin edits made in the console are never overwritten.
     */
    private function onboardingSlides(): void
    {
        $slides = [
            1 => 'Let the Experience Find You',
            2 => 'Event Planning Made Easy',
            3 => 'Show Your Work',
        ];
        $cloudinary = app(CloudinaryClient::class);
        foreach ($slides as $n => $title) {
            if (Banner::query()->where('placement', 'onboarding')->where('title', $title)->exists()) {
                continue;
            }
            $source = database_path('seeders/assets/onboarding/slide-'.$n.'.jpg');
            $url = null;
            if ($cloudinary->isConfigured()) {
                try {
                    $result = $cloudinary->upload($source, 'onboarding', 'slide-'.$n, 'image', overwrite: true);
                    $url = $cloudinary->url($result['public_id']);
                } catch (\Throwable $e) {
                    $this->command?->warn('Onboarding slide '.$n.' upload failed: '.$e->getMessage());
                }
            }
            if ($url === null) {
                @mkdir(public_path('images/onboarding'), 0775, true);
                copy($source, public_path('images/onboarding/slide-'.$n.'.jpg'));
                $url = rtrim((string) config('app.url'), '/').'/images/onboarding/slide-'.$n.'.jpg';
            }

            Banner::query()->create([
                'title' => $title, 'subtitle' => null, 'image_url' => $url, 'cta_label' => null, 'cta_url' => null,
                'placement' => 'onboarding', 'audience' => 'all', 'is_active' => true,
                'priority' => 100 - $n, // slide 1 first
            ]);
        }
    }

    private function eventCategories(): void
    {
        $categories = [
            ['Weddings', 'heart'], ['Birthdays', 'cake'], ['Concerts', 'music'], ['Parties', 'party-popper'],
            ['Conferences', 'presentation'], ['Religious', 'church'], ['Corporate', 'briefcase'], ['Festivals', 'tent'],
            ['Sports', 'trophy'], ['Arts & Culture', 'palette'], ['Food & Drink', 'utensils'], ['Networking', 'users'],
        ];
        foreach ($categories as $i => [$name, $icon]) {
            EventCategory::query()->updateOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'icon' => $icon, 'sort_order' => $i, 'is_active' => true]);
        }
    }

    private function vendorCategories(): void
    {
        $names = ['Catering', 'Photography', 'Videography', 'Decoration', 'Event Planning', 'Makeup & Beauty', 'Fashion & Tailoring',
            'Cakes & Desserts', 'DJ & Music', 'Live Band', 'MC & Hosting', 'Venue', 'Lighting & Sound', 'Rentals', 'Transportation',
            'Ushering', 'Security', 'Printing & Stationery', 'Florist', 'Drinks & Bar'];
        foreach ($names as $i => $name) {
            VendorCategory::query()->updateOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'sort_order' => $i, 'is_active' => true]);
        }
    }

    private function interests(): void
    {
        $labels = ['Weddings', 'Music', 'Fashion', 'Food', 'Art', 'Tech', 'Sports', 'Travel', 'Comedy', 'Movies', 'Faith',
            'Business', 'Health & Fitness', 'Photography', 'Gaming', 'Dance', 'Books', 'Parties', 'Culture', 'Charity'];
        foreach ($labels as $i => $label) {
            Interest::query()->updateOrCreate(['slug' => Str::slug($label)], ['label' => $label, 'sort_order' => $i, 'is_active' => true]);
        }
    }

    /** Prices from the Pricing design (₦/month; yearly = 10 months). */
    private function plans(): void
    {
        $plans = [
            ['free', 'Free', 'For everyone', 0, ['Unlimited work uploads', 'Basic vendor listing', 'Create and sell tickets'], null, false],
            ['basic', 'Basic', 'For organisers and vendors', 300000, ['Higher placement in vendor search', 'Advanced profile customisation', 'Event analytics'], 'free', false],
            ['premium', 'Premium', 'For organisers and vendors', 500000, ['Sub-events', 'Pluz Planner assistant', 'Design generation', 'Priority support'], 'basic', true],
            ['premium_plus', 'Premium+', 'For large organisations', 800000, ['Team seats', 'Dedicated account manager', 'Custom branding'], 'premium', false],
        ];
        $ids = [];
        foreach ($plans as $i => [$tier, $name, $tagline, $monthly, $features, $inherits, $popular]) {
            $plan = Plan::query()->updateOrCreate(['tier' => $tier], [
                'name' => $name, 'tagline' => $tagline, 'price_monthly' => $monthly, 'price_yearly' => $monthly * 10,
                'currency' => 'NGN', 'features' => $features, 'is_popular' => $popular, 'sort_order' => $i,
            ]);
            $ids[$tier] = $plan->id;
        }
        foreach ($plans as [$tier, , , , , $inherits]) {
            Plan::query()->where('tier', $tier)->update(['inherits_from' => $inherits ? $ids[$inherits] : null]);
        }
    }

    private function cmsPages(): void
    {
        $pages = [
            'about' => 'About Wedpluz', 'terms' => 'Terms and conditions', 'privacy' => 'Privacy policy',
            'faqs' => 'Frequently asked questions', 'help' => 'Help centre', 'contact' => 'Contact us',
        ];
        foreach ($pages as $slug => $title) {
            CmsPage::query()->firstOrCreate(['slug' => $slug], [
                'title' => $title,
                'content' => '<p>'.$title.' content is managed by the Wedpluz team in the admin console.</p>',
            ]);
        }
    }

    private function templates(): void
    {
        foreach (DefaultTemplates::all() as $key => $definition) {
            foreach (['email', 'sms', 'whatsapp', 'push'] as $channel) {
                $template = DefaultTemplates::get($key, $channel);
                if ($template === null) {
                    continue;
                }
                NotificationTemplate::query()->firstOrCreate(['key' => $key, 'channel' => $channel], [
                    'name' => $definition['name'],
                    'subject' => $template['subject'],
                    'body' => $template['body'],
                    'variables' => $template['variables'],
                    'is_active' => true,
                ]);
            }
        }
    }

    private function roles(): void
    {
        foreach (AdminPermission::defaultRoles() as $slug => $definition) {
            $role = AdminRole::query()->updateOrCreate(['slug' => $slug], [
                'name' => $definition['name'], 'description' => $definition['description'], 'is_system' => true,
            ]);
            $role->syncPermissions($definition['permissions']);
        }
    }

    private function commission(): void
    {
        if (! CommissionRule::query()->where('scope', 'platform')->where('is_active', true)->exists()) {
            CommissionRule::query()->create([
                'scope' => 'platform', 'scope_id' => null, 'scope_label' => 'Platform default',
                'percent' => 5, 'fixed_fee' => 0, 'currency' => 'NGN', 'effective_from' => now()->toDateString(),
            ]);
        }
    }
}
