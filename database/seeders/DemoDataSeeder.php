<?php

namespace Database\Seeders;

use App\Integrations\Storage\CloudinaryClient;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\Banner;
use App\Models\Community;
use App\Models\Conversation;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\EventManager;
use App\Models\EventTask;
use App\Models\Media;
use App\Models\StatusPost;
use App\Models\Ticket;
use App\Models\User;
use App\Models\VendorCategory;
use App\Models\VendorProfile;
use App\Models\VendorWork;
use App\Models\Wallet;
use App\Services\Checkout\CheckInService;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\OrderService;
use App\Services\Checkout\TicketQr;
use App\Services\Communities\CommunityService;
use App\Services\Events\EventService;
use App\Services\Finance\LedgerService;
use App\Services\Messaging\MessagingService;
use App\Services\Moderation\ReportService;
use App\Services\Notifications\NotificationService;
use App\Services\Users\UserProvisioner;
use App\Services\Vendors\VendorService;
use App\Services\Wallet\WalletService;
use App\Support\DemoAccounts;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Realistic demo data for local development and frontend testing.
 *
 *   php artisan db:seed --class=DemoDataSeeder
 *
 * Everything goes through the real domain services where invariants matter
 * (event creation, checkout and wallet payment, ticket issuance, check-in,
 * settlements, communities, messaging, vendor reviews), so counters, ledger
 * postings and notifications are consistent with production behaviour.
 *
 * - Production: refused unless DEMO_DATA_IN_PRODUCTION=true and DEMO_DATA_PASSWORD
 *   are set. There the password comes from DEMO_DATA_PASSWORD, no demo admin is
 *   created, and demo accounts never move money out (App\Support\DemoAccounts).
 * - Idempotent: skips when the demo data exists.
 * - All demo accounts use @demo.wedpluz.test (undeliverable by design) and the
 *   password below (or DEMO_DATA_PASSWORD). Queued jobs and mail are faked while seeding, so no email,
 *   SMS or push leaves the machine.
 * - Images are imported into Cloudinary (`<root>/demo/...`) when configured.
 */
class DemoDataSeeder extends Seeder
{
    public const PASSWORD = 'Wedpluz@2026';

    public const DOMAIN = DemoAccounts::DOMAIN;

    /** Demo admin TOTP secret (local only): add to an authenticator app, or use AUTH_DEV_FIXED_OTP. */
    public const ADMIN_TOTP_SECRET = 'WEDPLUZDEMOADMIN';

    /** Vetted Unsplash photos (weddings, décor, parties, concerts, food). */
    private const PHOTOS = [
        '1519741497674-611481863552', '1511795409834-ef04bbd61622', '1464366400600-7168b8af9bc3', '1530103862676-de8c9debad1d',
        '1492684223066-81342ee5ff30', '1465495976277-4387d4b0b4c6', '1505236858219-8359eb29e329', '1533174072545-7a4b6ad7a6c3',
        '1555244162-803834f70033', '1478146896981-b80fe463b330', '1519225421980-715cb0215aed', '1606800052052-a08af7148866',
        '1520854221256-17451cc331bf', '1469371670807-013ccf25f16a', '1515934751635-c81c6bc9a2d8', '1522673607200-164d1b6ce486',
        '1470225620780-dba8ba36b745', '1514525253161-7a46d19cd819', '1501281668745-f7f57925c3b4', '1540575467063-178a50c2df87',
        '1528605248644-14dd04022da1', '1467810563316-b5476525c0f9', '1504674900247-0877df9cc836', '1414235077428-338989a2e8c0',
        '1556910103-1c02745aae4d', '1495474472287-4d71bcdd2085', '1488477181946-6428a0291777', '1563729784474-d77dbb933a9e',
        '1527529482837-4698179dc6ce', '1516450360452-9312f5e86fc7', '1429962714451-bb934ecdc4ec', '1459749411175-04bf5292ceea',
        '1532712938310-34cb3982ef74', '1561912774-79769a0a0a7a', '1513151233558-d860c5398176', '1523438885200-e635ba2c371e',
        // 36-53: homepage "Discover Creative Professionals" vendors
        '1519657337289-077653f724ed', '1594552072238-b8a33785b261', '1525258946800-98cfd641d0de', '1550005809-91ad75fb315f',
        '1577219491135-ce391730fb2c', '1583394293214-28ded15ee548', '1600565193348-f74bd3c7ccdf', '1521119989659-a83eee488004',
        '1502823403499-6ccfcf4fb453', '1542038784456-1ea8e935640e', '1554080353-a576cf803bda', '1566174053879-31528523f8ae',
        '1519167758481-83f550bb49b3', '1511578314322-379afb476865', '1595407753234-0882f1e77954', '1583939003579-730e3918a45a',
        '1531746020798-e6953c6e8e04', '1494790108377-be9c29b29330',
    ];

    private string $password = self::PASSWORD;

    /** @var array<string,string> */
    private array $imageCache = [];

    /** @var array<string,User> */
    private array $users = [];

    /** @var array<string,VendorProfile> */
    private array $vendors = [];

    /** @var array<string,Event> */
    private array $events = [];

    /** @var list<string> */
    private array $designVendorIds = [];

    public function run(): void
    {
        if (! DemoAccounts::seedingAllowed()) {
            throw new RuntimeException('Demo data is disabled in production. Set DEMO_DATA_IN_PRODUCTION=true and DEMO_DATA_PASSWORD in .env to allow it.');
        }
        $this->password = $this->resolvePassword();
        if (User::query()->where('email', 'customer@'.self::DOMAIN)->exists()) {
            $this->command?->info('Demo data already present (customer@'.self::DOMAIN.'). Nothing to do.');

            return;
        }

        // Nothing leaves the machine while seeding: notification jobs, emails and image-import jobs are dropped.
        Queue::fake();
        Mail::fake();

        $steps = [
            'users' => fn () => $this->seedUsers(),
            'vendors' => fn () => $this->seedVendors(),
            'featured vendors' => fn () => $this->seedDesignVendors(),
            'events' => fn () => $this->seedEvents(),
            'wallets' => fn () => $this->seedWallets(),
            'orders and tickets' => fn () => $this->seedOrders(),
            'completed event' => fn () => $this->completeEvent(),
            'team and requests' => fn () => $this->seedCollaboration(),
            'communities' => fn () => $this->seedCommunities(),
            'messages' => fn () => $this->seedMessages(),
            'engagement' => fn () => $this->seedEngagement(),
            'platform content' => fn () => $this->seedPlatform(),
            'admin' => fn () => $this->seedAdmin(),
        ];
        // All or nothing: a failure rolls everything back (imported images stay cached in Cloudinary).
        DB::transaction(function () use ($steps) {
            foreach ($steps as $label => $step) {
                $start = microtime(true);
                $step();
                $this->command?->line(sprintf('  <info>✓</info> %-20s %5.1fs', $label, microtime(true) - $start));
            }
        });

        $this->printSummary();
    }

    // ------------------------------------------------------------------ users

    private function seedUsers(): void
    {
        $people = [
            // key, first, last, username, portrait, city, roles, extra
            ['ada', 'Adaeze', 'Okafor', 'ada.events', 'women/44', 'Lagos', ['organizer'], ['identity_status' => 'verified', 'bio' => 'Wedding & celebration planner. 8 years making Lagos parties unforgettable.']],
            ['tunde', 'Tunde', 'Bakare', 'tundebakare', 'men/32', 'Abuja', ['organizer'], ['identity_status' => 'verified', 'bio' => 'Food festivals, galas and good vibes in the FCT.']],
            ['kemi', 'Kemi', 'Adeyemi', 'kemi_adeyemi', 'women/68', 'Lagos', ['organizer'], ['bio' => 'Fashion lover planning my first big events.']],
            ['customer', 'Chioma', 'Nwosu', 'chioma', 'women/21', 'Lagos', [], ['bio' => 'Bride-to-be 💍 | Owambe enthusiast']],
            ['emeka', 'Emeka', 'Obi', 'emekaobi', 'men/11', 'Lagos', [], []],
            ['funmi', 'Funmilayo', 'Ajayi', 'funmi.a', 'women/12', 'Ibadan', [], []],
            ['ibrahim', 'Ibrahim', 'Sule', 'ibrahim_s', 'men/45', 'Abuja', [], []],
            ['ngozi', 'Ngozi', 'Eze', 'ngozieze', 'women/55', 'Enugu', [], []],
            ['segun', 'Segun', 'Alade', 'segun.alade', 'men/62', 'Lagos', [], []],
            ['amaka', 'Amaka', 'Okonkwo', 'amakaok', 'women/33', 'Port Harcourt', [], []],
            ['david', 'David', 'Etim', 'davidetim', 'men/75', 'Uyo', [], []],
            ['zainab', 'Zainab', 'Bello', 'zainab.b', 'women/85', 'Kano', [], []],
            ['bola', 'Bolaji', 'Ogunleye', 'bolaji_o', 'men/22', 'Lagos', [], []],
            // vendor owners
            ['v_catering', 'Halima', 'Musa', 'mamaputgourmet', 'women/29', 'Lagos', [], []],
            ['v_photo', 'Daniel', 'Eze', 'lensandlight', 'men/52', 'Lagos', [], []],
            ['v_decor', 'Grace', 'Akpan', 'bloomdecor', 'women/36', 'Lagos', [], []],
            ['v_makeup', 'Teni', 'Lawal', 'glambyteni', 'women/48', 'Lagos', [], []],
            ['v_cakes', 'Yemi', 'Ogunbanjo', 'sugarrushcakes', 'women/57', 'Abuja', [], []],
            ['v_dj', 'Osaze', 'Idahosa', 'djozzy', 'men/40', 'Lagos', [], []],
            ['v_mc', 'Chinedu', 'Obiora', 'mclaffs', 'men/18', 'Port Harcourt', [], []],
            ['v_venue', 'Musa', 'Bello', 'grandatrium', 'men/27', 'Abuja', [], []],
            ['v_fashion', 'Folake', 'Adebayo', 'adireandlace', 'women/63', 'Lagos', [], []],
            ['v_band', 'Kola', 'Martins', 'highlifeband', 'men/58', 'Lagos', [], []],
        ];

        $interests = ['weddings', 'music', 'fashion', 'food', 'parties', 'culture', 'photography', 'dance', 'business', 'art'];
        $provisioner = app(UserProvisioner::class);
        foreach ($people as $i => [$key, $first, $last, $username, $portrait, $city, $roles, $extra]) {
            $user = $provisioner->create(array_merge([
                'first_name' => $first,
                'last_name' => $last,
                'username' => $username,
                'email' => $key.'@'.self::DOMAIN,
                'phone' => '+23480'.str_pad((string) (31000000 + $i * 7919), 8, '0', STR_PAD_LEFT),
                'password' => $this->password,
                'email_verified_at' => now()->subDays(60 - $i),
                'phone_verified_at' => now()->subDays(60 - $i),
                'date_of_birth' => now()->subYears(24 + $i % 15)->subDays($i * 11)->toDateString(),
                'city' => $city,
                'country_code' => 'NG',
                'avatar_url' => $this->image('avatar-'.str_replace('/', '-', $portrait), 'https://randomuser.me/api/portraits/'.$portrait.'.jpg', 'avatars'),
                'profile_completed' => true,
                'interests_completed' => true,
                'signup_source' => $i % 3 === 0 ? 'android' : ($i % 3 === 1 ? 'web' : 'ios'),
                'last_seen_at' => now()->subMinutes($i * 37),
            ], $extra));
            $user->forceFill(['created_at' => now()->subDays(90 - $i * 3)])->saveQuietly();
            foreach ($roles as $role) {
                $user->addRole($role);
            }
            foreach (array_slice($interests, $i % 5, 4) as $slug) {
                DB::table('user_interests')->insertOrIgnore(['user_id' => $user->id, 'interest_slug' => $slug]);
            }
            $this->users[$key] = $user;
        }

        // Paid plans for the two established organisers.
        $plans = DB::table('plans')->pluck('id', 'tier');
        foreach (['ada' => 'premium', 'tunde' => 'basic'] as $key => $tier) {
            DB::table('subscriptions')->insert([
                'id' => 'sub_'.Str::ulid(), 'user_id' => $this->users[$key]->id, 'plan_id' => $plans[$tier], 'interval' => 'monthly',
                'status' => 'active', 'started_at' => now()->subDays(20), 'renews_at' => now()->addDays(10), 'provider' => 'paystack',
                'created_at' => now()->subDays(20), 'updated_at' => now(),
            ]);
            $this->users[$key]->forceFill(['plan' => $tier])->saveQuietly();
        }
    }

    // ---------------------------------------------------------------- vendors

    private function seedVendors(): void
    {
        $vendors = [
            // owner key, name, category slug, location, description, cover photo, work photos, status, featured
            ['v_catering', 'Mama Put Gourmet Catering', 'catering', 'Lekki, Lagos', 'Party jollof that ends arguments. Nigerian and continental menus for 50 to 2,000 guests, with small chops, grills and live cooking stations.', 23, [[8, 22], [24, 23]], 'active', false],
            ['v_photo', 'Lens & Light Studios', 'photography', 'Yaba, Lagos', 'Documentary wedding photography and cinematic films. Pre-wedding shoots, traditional and white weddings, same-day highlight edits.', 32, [[0, 5], [12, 15]], 'active', false],
            ['v_decor', 'Bloom Décor & Events', 'decoration', 'Ikoyi, Lagos', 'Luxury floral design, stage backdrops and table styling. From intimate dinners to 1,000-guest ballrooms.', 13, [[1, 10], [13, 33], [35, 2]], 'active', false],
            ['v_makeup', 'Glam by Teni', 'makeup-beauty', 'Victoria Island, Lagos', 'Bridal makeup and gele tying for the bride and her squad. Soft glam to full owambe beat.', 9, [[9, 14]], 'active', false],
            ['v_cakes', 'Sugar Rush Cakes', 'cakes-desserts', 'Wuse II, Abuja', 'Wedding cakes, dessert tables and cupcakes baked fresh in Abuja. Tastings by appointment.', 27, [[27, 26]], 'active', false],
            ['v_dj', 'DJ Ozzy Sounds', 'dj-music', 'Surulere, Lagos', 'Afrobeats, amapiano and old-school highlife. Full sound and lighting rig for parties of any size.', 17, [[16, 17], [29, 6]], 'active', false],
            ['v_mc', 'MC Laffs', 'mc-hosting', 'GRA, Port Harcourt', 'Bilingual MC who keeps your reception on time and your guests laughing.', 19, [[19, 28]], 'active', false],
            ['v_venue', 'The Grand Atrium', 'venue', 'Maitama, Abuja', 'Pillar-free hall for 800 guests, garden terrace and bridal suite. In-house décor partners and ample parking.', 35, [[2, 33], [35, 1]], 'active', false],
            ['v_fashion', 'Adire & Lace Couture', 'fashion-tailoring', 'Surulere, Lagos', 'Bespoke aso-ebi, bridal robes and adire statement pieces.', 9, [[11, 9]], 'pending_verification', false],
            ['v_band', 'Highlife Live Band', 'live-band', 'Ikeja, Lagos', 'Eight-piece live band: highlife, juju and Afro-soul classics for weddings and corporate nights.', 30, [[30, 31], [18, 4]], 'active', false],
        ];

        $categories = VendorCategory::query()->pluck('id', 'slug');
        $service = app(VendorService::class);
        foreach ($vendors as $i => [$ownerKey, $name, $category, $location, $description, $cover, $works, $status, $featured]) {
            $owner = $this->users[$ownerKey];
            $profile = $service->create($owner, [
                'businessName' => $name,
                'categoryId' => $categories[$category],
                'location' => $location,
                'description' => $description,
                'contactPhone' => $owner->phone,
                'website' => 'https://'.Str::slug($name).'.example.com',
                'socials' => ['instagram' => '@'.$owner->username],
            ]);
            $profile->forceFill([
                'avatar_url' => $owner->avatar_url,
                'cover_url' => $this->photo($cover, 'vendors'),
                'status' => $status,
                'verified_at' => $status === 'active' ? now()->subDays(30 - $i) : null,
                'contact_email_verified_at' => now()->subDays(30),
                'is_featured' => $featured,
            ])->save();

            foreach ($works as $w => $photos) {
                $work = VendorWork::query()->create([
                    'vendor_profile_id' => $profile->id,
                    'title' => ['Recent highlights', 'Signature work', 'Behind the scenes'][$w] ?? 'Portfolio',
                    'description' => 'A selection from recent events by '.$name.'.',
                ]);
                foreach ($photos as $sort => $photo) {
                    Media::query()->create([
                        'owner_type' => 'work', 'owner_id' => $work->id, 'type' => 'image',
                        'url' => $this->photo($photo, 'works'), 'thumbnail_url' => $this->photo($photo, 'works'),
                        'width' => 1600, 'height' => 1067, 'provider' => 'cloudinary', 'sort_order' => $sort,
                    ]);
                }
                $profile->increment('works_count');
            }
            $service->createPromo($profile, [
                'title' => $i % 2 ? 'Early booking discount' : 'Weekday special',
                'description' => 'Book before the end of next month and save on your package.',
                'discountPercent' => [10, 15, 20][$i % 3],
                'validUntil' => now()->addDays(45)->toDateString(),
            ]);
            $this->vendors[$ownerKey] = $profile;
        }
    }

    /**
     * The five vendors on the homepage design ("Discover Creative Professionals
     * with Ease" carousel; two of them also in "Hire a Vendor"): same names,
     * handles (= slug), card labels (= headline), cities and 4.9 ratings, with
     * look-alike photos. They are the only featured vendors, ordered by
     * rating then likes so GET /vendors/featured returns them in design order.
     */
    private function seedDesignVendors(): void
    {
        $cards = [
            // owner key, first, last, username, avatar photo, business name, slug (@handle), headline (card label), category, location, cover, works (newest last), ratings, likes
            ['d_bea', 'Blessing', 'Amadi', 'beayouwed', 52, 'Bea-you-wedz', 'bea-you-wed', 'Bridal wear rentals', 'rentals', 'Port Harcourt', 36, [[39, 0], [36, 37]], [5, 5, 5, 5, 5, 5, 5, 5, 5, 4], 9],
            ['d_chef', 'Femi', 'Adeleke', 'bestchefz', 41, 'Best Chefz', 'bestchefz', 'Caterer', 'catering', 'Lekki, Lagos', 8, [[40, 42], [8, 22]], [5, 5, 5, 5, 5, 5, 5, 5, 5, 4], 7],
            ['d_shot', 'Tobi', 'Daniels', 'shotitmoi', 45, 'Shot it moi', 'shot-it-moi', 'Photographer', 'photography', 'Port Harcourt', 44, [[48, 47], [44, 0]], [5, 5, 5, 5, 5, 5, 5, 5, 5, 4], 5],
            ['d_plan', 'Ifeoma', 'Chukwu', 'yournextevent', 53, 'Your Next Event', 'yournextevent', 'Event Planner', 'event-planning', 'Wuse II, Abuja', 28, [[50, 51], [28, 20]], [5, 5, 5, 5, 5, 5, 5, 5, 4, 4], 3],
            ['d_rent', 'Kayode', 'Martins', 'eventalrentals', 43, 'Evental Rentals', 'eventalrentals', 'Rental provider', 'rentals', 'Ikeja, Lagos', 48, [[33, 49], [48, 2]], [5, 5, 5, 5, 5, 5, 5, 4, 4, 4], 2],
        ];
        $reviewers = ['customer', 'emeka', 'funmi', 'ibrahim', 'ngozi', 'segun', 'amaka', 'david', 'zainab', 'bola'];
        $comments = [
            'Absolutely professional from start to finish.', 'Exceeded our expectations, guests are still talking about it.',
            'Responsive, on time and worth every naira.', 'Beautiful work. Would book again.', 'They made our day stress-free.',
            'Great communication and lovely results.', 'Highly recommended for weddings.', 'Top quality and very friendly team.',
            'Good service, small delay on the day.', 'Lovely experience overall.',
        ];

        $categories = VendorCategory::query()->pluck('id', 'slug');
        $vendors = app(VendorService::class);
        $provisioner = app(UserProvisioner::class);
        foreach ($cards as $n => [$key, $first, $last, $username, $avatar, $name, $slug, $headline, $category, $location, $cover, $works, $ratings, $likes]) {
            $owner = $provisioner->create([
                'first_name' => $first, 'last_name' => $last, 'username' => $username, 'email' => $key.'@'.self::DOMAIN,
                'phone' => '+23481'.str_pad((string) (52000000 + $n * 7577), 8, '0', STR_PAD_LEFT), 'password' => $this->password,
                'email_verified_at' => now()->subDays(80), 'phone_verified_at' => now()->subDays(80), 'city' => explode(', ', $location)[count(explode(', ', $location)) - 1],
                'country_code' => 'NG', 'avatar_url' => $this->photo($avatar, 'avatars'), 'profile_completed' => true, 'interests_completed' => true,
                'identity_status' => 'verified', 'signup_source' => 'web',
            ]);
            $this->users[$key] = $owner;

            $profile = $vendors->create($owner, [
                'businessName' => $name, 'headline' => $headline, 'categoryId' => $categories[$category], 'location' => $location,
                'description' => $name.' — '.$headline.' based in '.$location.'. Booking for weddings, birthdays and corporate events across Nigeria.',
                'contactPhone' => $owner->phone, 'website' => 'https://'.$slug.'.example.com', 'socials' => ['instagram' => '@'.$slug],
            ]);
            $profile->forceFill([
                'slug' => $slug, 'avatar_url' => $owner->avatar_url, 'cover_url' => $this->photo($cover, 'vendors'), 'status' => 'active',
                'verified_at' => now()->subDays(60), 'contact_email_verified_at' => now()->subDays(60), 'is_featured' => true,
            ])->save();

            foreach ($works as $w => $photos) {
                $work = VendorWork::query()->create([
                    'vendor_profile_id' => $profile->id, 'title' => $w === 0 ? 'Recent jobs' : 'Signature work',
                    'description' => 'Selected work by '.$name.'.', 'created_at' => now()->subDays(20 - $w), 'updated_at' => now()->subDays(20 - $w),
                ]);
                foreach ($photos as $sort => $photo) {
                    Media::query()->create([
                        'owner_type' => 'work', 'owner_id' => $work->id, 'type' => 'image',
                        'url' => $this->photo($photo, 'works'), 'thumbnail_url' => $this->photo($photo, 'works'),
                        'width' => 1600, 'height' => 1067, 'provider' => 'cloudinary', 'sort_order' => $sort,
                    ]);
                }
                $profile->increment('works_count');
            }

            foreach ($ratings as $i => $rating) {
                $vendors->review($profile, $this->users[$reviewers[$i]], $rating, $comments[($i + $n) % count($comments)]);
            }
            $firstWork = VendorWork::query()->where('vendor_profile_id', $profile->id)->orderBy('created_at')->first();
            foreach (array_slice($reviewers, 0, $likes) as $liker) {
                $vendors->toggleLike($firstWork, $this->users[$liker]);
            }
            $this->vendors[$key] = $profile->refresh();
            $this->designVendorIds[] = $profile->id;
        }
    }

    // ----------------------------------------------------------------- events

    private function seedEvents(): void
    {
        $c = EventCategory::query()->pluck('id', 'slug');
        $venues = [
            'eko' => ['name' => 'Eko Hotel & Suites', 'address' => 'Plot 1415 Adetokunbo Ademola St, Victoria Island', 'city' => 'Lagos', 'state' => 'Lagos', 'countryCode' => 'NG', 'lat' => 6.4281, 'lng' => 3.4219],
            'ikoyi' => ['name' => 'The Civic Centre', 'address' => 'Ozumba Mbadiwe Ave, Victoria Island', 'city' => 'Lagos', 'state' => 'Lagos', 'countryCode' => 'NG', 'lat' => 6.4389, 'lng' => 3.4253],
            'landmark' => ['name' => 'Landmark Beach', 'address' => 'Water Corporation Rd, Oniru', 'city' => 'Lagos', 'state' => 'Lagos', 'countryCode' => 'NG', 'lat' => 6.4218, 'lng' => 3.4497],
            'lekki' => ['name' => 'The Art Loft', 'address' => '12 Admiralty Way, Lekki Phase 1', 'city' => 'Lagos', 'state' => 'Lagos', 'countryCode' => 'NG', 'lat' => 6.4474, 'lng' => 3.4739],
            'abuja_park' => ['name' => 'Millennium Park', 'address' => 'Maitama District', 'city' => 'Abuja', 'state' => 'FCT', 'countryCode' => 'NG', 'lat' => 9.0886, 'lng' => 7.5039],
            'transcorp' => ['name' => 'Transcorp Hilton', 'address' => '1 Aguiyi Ironsi St, Maitama', 'city' => 'Abuja', 'state' => 'FCT', 'countryCode' => 'NG', 'lat' => 9.0732, 'lng' => 7.4917],
            'atrium' => ['name' => 'The Grand Atrium', 'address' => '5 Gana St, Maitama', 'city' => 'Abuja', 'state' => 'FCT', 'countryCode' => 'NG', 'lat' => 9.0835, 'lng' => 7.4950],
            'ph' => ['name' => 'Hotel Presidential', 'address' => 'Aba Rd, GRA Phase 2', 'city' => 'Port Harcourt', 'state' => 'Rivers', 'countryCode' => 'NG', 'lat' => 4.8156, 'lng' => 7.0498],
        ];
        $T = fn (string $name, int $naira, int $qty, array $extra = []) => array_merge(['name' => $name, 'price' => $naira * 100, 'quantity' => $qty, 'perOrderLimit' => 10], $extra);

        $events = [
            'wedding' => ['ada', 'Tolu & Femi\'s White Wedding', 'weddings', 13, [0, 5, 11, 15], 21, 8, 'eko', [$T('Regular', 25000, 300), $T('VIP', 75000, 80, ['description' => 'Front-row seating, cocktail reception and gift bag']), $T('Table for 10', 600000, 20, ['perOrderLimit' => 2])], ['is_featured' => true, 'tags' => ['wedding', 'white wedding', 'lagos']]],
            'owambe' => ['ada', 'Owambe Saturday: Aso-Ebi Edition', 'parties', 28, [3, 20, 34], 9, 7, 'ikoyi', [$T('Regular', 15000, 400), $T('VIP', 50000, 60)], ['is_featured' => true, 'tags' => ['owambe', 'aso-ebi', 'party']]],
            'fair' => ['ada', 'Lagos Bridal Fair 2026', 'weddings', 33, [1, 10, 14], 35, 9, 'eko', [$T('Day pass', 5000, 1500), $T('Weekend pass', 8000, 800)], ['tags' => ['bridal fair', 'vendors', 'expo'], 'two_days' => true]],
            'afro' => ['ada', 'Afrobeats Under The Stars', 'concerts', 17, [6, 18, 30], 14, 6, 'landmark', [$T('Early bird', 10000, 200, ['isEarlyBird' => true]), $T('Regular', 20000, 1200), $T('VIP', 60000, 150)], ['is_featured' => true, 'tags' => ['afrobeats', 'concert', 'beach']]],
            'paint' => ['ada', 'Sip & Paint: Wine Edition', 'arts-culture', 23, [25, 26], 6, 3, 'lekki', [$T('Seat', 18000, 40, ['perOrderLimit' => 2])], ['approvalRequired' => true, 'tags' => ['sip and paint', 'wine', 'art']]],
            'masterclass' => ['ada', 'Planning a Wedding on a Budget (Masterclass)', 'conferences', 19, [], 12, 2, null, [$T('Free seat', 0, 500)], ['isFree' => true, 'online' => true, 'tags' => ['online', 'wedding planning']]],
            'engagement' => ['ada', 'Kunle & Bisi Engagement Party', 'parties', 12, [15, 35], 0, 6, 'ikoyi', [$T('Regular', 10000, 150)], ['tags' => ['engagement', 'party'], 'today' => true]],
            'foodfest' => ['tunde', 'Abuja Food & Drinks Festival', 'food-drink', 22, [8, 24, 27], 18, 10, 'abuja_park', [$T('Regular', 5000, 3000), $T('VIP lounge', 25000, 200)], ['is_featured' => true, 'tags' => ['food', 'festival', 'abuja']]],
            'gala' => ['tunde', 'Corporate Gala Night 2026', 'corporate', 2, [33, 1], 40, 5, 'transcorp', [$T('Single', 100000, 300), $T('Couple', 180000, 150)], ['tags' => ['gala', 'corporate', 'black tie']]],
            'comedy' => ['tunde', 'Port Harcourt Comedy & Music Night', 'concerts', 7, [29, 31], 25, 5, 'ph', [$T('Regular', 7500, 800), $T('Table for 6', 150000, 30, ['perOrderLimit' => 1])], ['tags' => ['comedy', 'music', 'port harcourt']]],
            'brunch' => ['tunde', 'Sunday Brunch & Networking', 'networking', 25, [26, 23], 4, 3, 'atrium', [$T('Free entry', 0, 120)], ['isFree' => true, 'tags' => ['brunch', 'networking']]],
            'birthday' => ['kemi', 'Kemi\'s 30th Birthday Bash', 'birthdays', 3, [21, 34], 30, 6, 'lekki', [$T('Guest', 20000, 150)], ['status' => 'pending_review', 'tags' => ['birthday', 'party']]],
            'showcase' => ['kemi', 'Wedding Fashion Showcase', 'arts-culture', 9, [11], 50, 4, 'ikoyi', [$T('General', 12000, 200)], ['status' => 'draft', 'tags' => ['fashion', 'bridal']]],
        ];

        $service = app(EventService::class);
        foreach ($events as $key => [$owner, $title, $category, $cover, $gallery, $days, $hours, $venue, $types, $opts]) {
            $organizer = $this->users[$owner];
            $start = ! empty($opts['today'])
                ? now()->addHours(2)->startOfHour()
                : now()->addDays($days)->setTime($days % 2 ? 16 : 12, 0);
            $schedules = [[
                'label' => ! empty($opts['two_days']) ? 'Day 1' : null,
                'startsAt' => $start->toIso8601String(),
                'endsAt' => $start->copy()->addHours($hours)->toIso8601String(),
                'attendanceMode' => ! empty($opts['online']) ? 'online' : 'physical',
                'venue' => $venue ? $venues[$venue] : null,
                'online' => ! empty($opts['online']) ? ['platform' => 'zoom', 'link' => 'https://zoom.us/j/0000000000'] : null,
            ]];
            if (! empty($opts['two_days'])) {
                $schedules[] = array_merge($schedules[0], ['label' => 'Day 2', 'startsAt' => $start->copy()->addDay()->toIso8601String(), 'endsAt' => $start->copy()->addDay()->addHours($hours)->toIso8601String()]);
            }

            $event = $service->create($organizer, [
                'title' => $title,
                'description' => $this->eventDescription($title, $category),
                'coverUrl' => $this->photo($cover, 'events'),
                'categoryId' => $c[$category],
                'visibility' => 'public',
                'approvalRequired' => (bool) ($opts['approvalRequired'] ?? false),
                'isFree' => (bool) ($opts['isFree'] ?? false),
                'allowGuestInvites' => true,
                'maxAdditionalGuests' => 3,
                'tags' => $opts['tags'] ?? [],
                'gallery' => array_map(fn ($p) => $this->photo($p, 'events'), $gallery),
                'schedules' => $schedules,
                'ticketTypes' => $types,
                'organizerInfo' => ['displayName' => $organizer->display_name, 'bio' => $organizer->bio, 'contactEmail' => $organizer->email],
            ]);

            $status = $opts['status'] ?? 'live';
            $event->forceFill(array_filter([
                'status' => $status,
                'is_featured' => (bool) ($opts['is_featured'] ?? false),
                'submitted_at' => $status !== 'draft' ? now()->subDays(3) : null,
                'published_at' => $status === 'live' ? now()->subDays(2) : null,
                'reviewed_at' => $status === 'live' ? now()->subDays(2) : null,
                'community_enabled' => true,
            ], fn ($v) => $v !== null))->save();
            $service->recalculate($event);
            if ($status === 'live') {
                $service->goLive($event->refresh());
            }
            $this->events[$key] = $event->refresh();
        }

        // A promo code buyers can try at checkout.
        DB::table('promo_codes')->insert([
            'id' => 'prm_'.Str::ulid(), 'code' => 'AFRO10', 'event_id' => $this->events['afro']->id, 'type' => 'percent', 'value' => 10,
            'max_uses' => 100, 'used_count' => 0, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(14), 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function eventDescription(string $title, string $category): string
    {
        $lines = [
            'weddings' => 'Join us as we celebrate love with family and friends. Expect great food, live music, a dance floor that never empties and memories for a lifetime.',
            'parties' => 'Dress code: aso-ebi or your sharpest owambe fit. Small chops, cocktails, a live DJ and spraying all night long.',
            'concerts' => 'An unforgettable night of live performances, top DJs and a crowd that knows every word. Gates open two hours before showtime.',
            'arts-culture' => 'A relaxed, creative experience hosted by local artists. All materials are provided, no experience needed.',
            'conferences' => 'Practical sessions, real budgets and a live Q&A. Bring your questions and a notebook.',
            'food-drink' => 'Over 60 vendors serving suya, jollof, grills, desserts and craft drinks, with live music and games for the whole family.',
            'corporate' => 'An evening of recognition, fine dining and live entertainment for leaders across industries. Black tie.',
            'networking' => 'Good food, great coffee and better conversations. Meet founders, creatives and professionals.',
            'birthdays' => 'Thirty, flirty and thriving! Come celebrate with cake, cocktails and a dance floor.',
        ];

        return '<p><strong>'.e($title).'</strong></p><p>'.($lines[$category] ?? $lines['parties']).'</p><ul><li>Valid ticket required at the gate</li><li>Tickets are sent to your email and the Wedpluz app</li></ul>';
    }

    // ---------------------------------------------------------------- wallets

    private function seedWallets(): void
    {
        $ledger = app(LedgerService::class);
        $wallets = app(WalletService::class);
        $notifications = app(NotificationService::class);
        $funded = ['customer' => 1500000, 'emeka' => 800000, 'funmi' => 600000, 'ibrahim' => 700000, 'ngozi' => 500000, 'segun' => 900000,
            'amaka' => 600000, 'david' => 400000, 'zainab' => 500000, 'bola' => 700000, 'ada' => 0, 'tunde' => 0];

        foreach ($funded as $key => $naira) {
            $user = $this->users[$key];
            $wallet = Wallet::query()->create([
                'user_id' => $user->id, 'currency' => 'NGN', 'is_active' => true, 'pin_hash' => Hash::make('1234'),
                'daily_withdrawal_limit' => (int) config('wedpluz.wallet.daily_withdrawal_limit'), 'activated_at' => now()->subDays(30),
            ]);
            if ($naira === 0) {
                continue;
            }
            // Two top-ups each, recorded exactly like a provider-confirmed top-up.
            foreach ([(int) ($naira * 0.6), $naira - (int) ($naira * 0.6)] as $n => $amount) {
                DB::transaction(function () use ($ledger, $wallets, $wallet, $amount, $n) {
                    $tx = $wallets->record($wallet, 'top_up', 'credit', $amount * 100, 'successful', 'Wallet top-up via Paystack', [
                        'provider' => 'paystack', 'counterparty' => 'Paystack', 'provider_reference' => 'demo_'.Str::lower(Str::random(12)), 'completed_at' => now()->subDays(20 - $n * 5),
                    ]);
                    $ledger->post([
                        LedgerService::debit('provider:paystack', $amount * 100),
                        LedgerService::credit($wallet->availableAccount(), $amount * 100),
                    ], 'NGN', 'wallet_transaction', $tx->id, 'Wallet top-up');
                    $tx->forceFill(['created_at' => now()->subDays(20 - $n * 5)])->saveQuietly();
                });
            }
            $notifications->notify($user, 'wallet_credit', ['tokens' => ['amount' => Money::format($naira * 100)], 'data' => ['walletId' => $wallet->id]]);
        }
    }

    // ----------------------------------------------------------------- orders

    private function seedOrders(): void
    {
        $plan = [
            // event => [buyer => [ticket type index => qty]]
            'wedding' => ['customer' => [1 => 2], 'emeka' => [0 => 2], 'funmi' => [0 => 1], 'segun' => [1 => 1], 'bola' => [0 => 3], 'amaka' => [0 => 2]],
            'owambe' => ['customer' => [0 => 2], 'ngozi' => [0 => 1], 'zainab' => [1 => 1], 'emeka' => [0 => 1], 'david' => [0 => 2]],
            'fair' => ['customer' => [1 => 1], 'funmi' => [0 => 2], 'amaka' => [1 => 1]],
            'afro' => ['customer' => [0 => 2], 'segun' => [1 => 2], 'bola' => [2 => 1], 'ibrahim' => [1 => 1], 'emeka' => [0 => 1], 'zainab' => [1 => 2]],
            'masterclass' => ['customer' => [0 => 1], 'ngozi' => [0 => 1], 'funmi' => [0 => 1], 'amaka' => [0 => 1]],
            'engagement' => ['customer' => [0 => 2], 'emeka' => [0 => 1], 'segun' => [0 => 2], 'bola' => [0 => 1], 'funmi' => [0 => 1]],
            'foodfest' => ['ibrahim' => [0 => 4], 'zainab' => [1 => 2], 'customer' => [0 => 2], 'david' => [0 => 2]],
            'gala' => ['ibrahim' => [1 => 1], 'segun' => [0 => 1]],
            'comedy' => ['amaka' => [0 => 2], 'david' => [0 => 3]],
            'brunch' => ['ibrahim' => [0 => 1], 'zainab' => [0 => 1], 'customer' => [0 => 1]],
        ];

        $checkout = app(CheckoutService::class);
        $orders = app(OrderService::class);
        foreach ($plan as $eventKey => $buyers) {
            $event = $this->events[$eventKey];
            $types = $event->ticketTypes()->orderBy('sort_order')->get()->values();
            foreach ($buyers as $buyerKey => $lines) {
                $buyer = $this->users[$buyerKey];
                $quote = $checkout->quote($buyer, [
                    'eventId' => $event->id,
                    'lines' => collect($lines)->map(fn ($qty, $index) => ['ticketTypeId' => $types[$index]->id, 'quantity' => $qty])->values()->all(),
                ]);
                // Plus-ones (free guest passes on top of purchased tickets) on a couple of orders only.
                $plusOnes = ['wedding:customer' => [['firstName' => 'Obinna', 'lastName' => 'Nwosu']], 'owambe:ngozi' => [['firstName' => 'Chidi', 'lastName' => 'Eze']]][$eventKey.':'.$buyerKey] ?? [];
                $orders->create($buyer, [
                    'quoteId' => $quote->id,
                    'attendee' => ['firstName' => $buyer->first_name, 'lastName' => $buyer->last_name, 'email' => $buyer->email, 'phone' => $buyer->phone],
                    'additionalGuests' => $plusOnes,
                    'paymentMethod' => $quote->total === 0 ? 'free' : 'wallet',
                    'returnUrl' => rtrim((string) config('wedpluz.web_origin'), '/').'/checkout/return',
                ], 'demo-'.$eventKey.'-'.$buyerKey);
            }
        }
    }

    /** Check guests in at the event happening today, then move it into the past and complete it (opens a settlement). */
    private function completeEvent(): void
    {
        $event = $this->events['engagement'];
        $organizer = $this->users['ada'];
        $tickets = Ticket::query()->where('event_id', $event->id)->where('status', 'valid')->orderBy('created_at')->get();
        $checkIn = app(CheckInService::class);
        foreach ($tickets->take(max(1, (int) ($tickets->count() * 0.75))) as $ticket) {
            $checkIn->validate($event, $organizer, TicketQr::payload($ticket), 'scan', 'Demo scanner');
        }

        $shift = fn ($column) => DB::raw("$column - interval '10 days'");
        DB::table('event_schedules')->where('event_id', $event->id)->update(['starts_at' => $shift('starts_at'), 'ends_at' => $shift('ends_at')]);
        DB::table('events')->where('id', $event->id)->update(['starts_at' => $shift('starts_at'), 'ends_at' => $shift('ends_at'), 'published_at' => now()->subDays(25)]);
        DB::table('tickets')->where('event_id', $event->id)->whereNotNull('checked_in_at')->update(['checked_in_at' => $shift('checked_in_at')]);
        DB::table('check_ins')->where('event_id', $event->id)->update(['created_at' => $shift('created_at')]);
        app(EventService::class)->complete($event->refresh());
        $this->events['engagement'] = $event->refresh();
    }

    // ---------------------------------------------------------- collaboration

    private function seedCollaboration(): void
    {
        $wedding = $this->events['wedding'];
        $ada = $this->users['ada'];

        EventManager::query()->create([
            'event_id' => $wedding->id, 'user_id' => $this->users['tunde']->id, 'invited_by_user_id' => $ada->id, 'access' => 'partial',
            'permissions' => ['manage_guests', 'scan_tickets'], 'status' => 'accepted', 'invited_at' => now()->subDays(6), 'responded_at' => now()->subDays(5),
        ]);
        EventManager::query()->create([
            'event_id' => $this->events['afro']->id, 'user_id' => $this->users['kemi']->id, 'invited_by_user_id' => $ada->id, 'access' => 'full',
            'permissions' => [], 'status' => 'pending', 'invited_at' => now()->subDay(),
        ]);

        foreach (['v_decor' => 'accepted', 'v_photo' => 'accepted', 'v_catering' => 'accepted', 'v_cakes' => 'accepted', 'v_mc' => 'pending'] as $vendorKey => $status) {
            DB::table('event_vendor_assignments')->insert([
                'id' => 'eva_'.Str::ulid(), 'event_id' => $wedding->id, 'vendor_profile_id' => $this->vendors[$vendorKey]->id, 'invited_by_user_id' => $ada->id,
                'role' => $this->vendors[$vendorKey]->business_name, 'status' => $status, 'invited_at' => now()->subDays(8),
                'responded_at' => $status === 'accepted' ? now()->subDays(7) : null, 'created_at' => now()->subDays(8), 'updated_at' => now(),
            ]);
        }

        $tasks = [
            ['Confirm final guest count with caterer', 'done', -2, 'v_catering'],
            ['Approve stage backdrop design', 'in_progress', 3, 'v_decor'],
            ['Pre-wedding shoot at Lekki Conservation Centre', 'done', -5, 'v_photo'],
            ['Cake tasting: pick 3 flavours', 'todo', 6, 'v_cakes'],
            ['Send programme to MC', 'todo', 10, null],
            ['Print seating chart and table cards', 'todo', 14, null],
        ];
        foreach ($tasks as [$title, $status, $due, $vendorKey]) {
            $task = EventTask::query()->create([
                'event_id' => $wedding->id, 'title' => $title, 'description' => null, 'status' => $status, 'due_at' => now()->addDays($due),
                'created_by_user_id' => $ada->id, 'completed_at' => $status === 'done' ? now()->addDays($due) : null,
            ]);
            if ($vendorKey) {
                DB::table('event_task_assignees')->insert(['task_id' => $task->id, 'user_id' => $this->users[$vendorKey]->id]);
            }
        }

        // Attendance requests on the approval-only event.
        foreach (['emeka', 'ngozi', 'david'] as $i => $key) {
            DB::table('event_requests')->insert([
                'id' => 'req_'.Str::ulid(), 'event_id' => $this->events['paint']->id, 'user_id' => $this->users[$key]->id,
                'first_name' => $this->users[$key]->first_name, 'last_name' => $this->users[$key]->last_name, 'status' => $i === 0 ? 'approved' : 'pending',
                'decided_by_user_id' => $i === 0 ? $ada->id : null, 'decided_at' => $i === 0 ? now()->subHours(5) : null,
                'created_at' => now()->subDays(2 - $i * 0.5), 'updated_at' => now(),
            ]);
        }
    }

    // ------------------------------------------------------------ communities

    private function seedCommunities(): void
    {
        $service = app(CommunityService::class);
        $groups = [
            ['ada', 'Lagos Brides Club', 'Planning tips, vendor recommendations and moral support for Lagos brides-to-be.', 14, ['customer', 'funmi', 'amaka', 'ngozi', 'zainab', 'v_decor', 'v_makeup']],
            ['tunde', 'Owambe Lovers', 'Where the party people gather. Share your fits, find the next big owambe.', 28, ['customer', 'emeka', 'segun', 'bola', 'david', 'ibrahim', 'v_dj']],
        ];
        foreach ($groups as [$ownerKey, $name, $description, $photo, $members]) {
            $community = $service->create($this->users[$ownerKey], ['name' => $name, 'description' => $description, 'permissions' => ['membersCanPostStatus' => true]], null);
            $community->forceFill(['avatar_url' => $this->photo($photo, 'communities')])->save();
            foreach ($members as $key) {
                $service->join($community, $this->users[$key], viaInvite: true);
            }
        }

        // Statuses (24 h stories) in each community, including the wedding's event community.
        $posts = [
            ['Lagos Brides Club', 'ada', 1, 'Table styling inspo for this weekend ✨'],
            ['Lagos Brides Club', 'v_decor', 13, 'Fresh install for a garden ceremony today 🌸'],
            ['Lagos Brides Club', 'customer', 5, 'Said yes!! Planning starts now 💍'],
            ['Owambe Lovers', 'tunde', 20, 'Last night was a movie 🎉'],
            ['Owambe Lovers', 'v_dj', 16, 'Booth set. Who is coming out tonight?'],
            ['Owambe Lovers', 'segun', 4, 'Confetti everywhere 😂'],
        ];
        $communities = Community::query()->pluck('id', 'name');
        foreach ($posts as $i => [$communityName, $authorKey, $photo, $caption]) {
            StatusPost::query()->create([
                'community_id' => $communities[$communityName], 'author_user_id' => $this->users[$authorKey]->id,
                'media_url' => $this->photo($photo, 'statuses'), 'media_type' => 'image', 'caption' => $caption,
                'likes_count' => 3 + $i * 2, 'views_count' => 12 + $i * 7, 'expires_at' => now()->addHours(20 - $i),
                'created_at' => now()->subHours(4 + $i), 'updated_at' => now(),
            ]);
        }
    }

    // --------------------------------------------------------------- messages

    private function seedMessages(): void
    {
        $messaging = app(MessagingService::class);
        $say = function (string $from, $conversation, string $body) use ($messaging) {
            return $messaging->send($conversation, $this->users[$from], ['clientId' => 'demo-'.Str::uuid(), 'type' => 'text', 'body' => $body]);
        };
        $thread = function (string $a, string $b, array $lines, bool $leaveAsRequest = false) use ($messaging, $say) {
            $conversation = $messaging->startDirect($this->users[$a], $this->users[$b]->id);
            foreach ($lines as $n => [$who, $text]) {
                if ($n > 0 && $who === $b && ! $leaveAsRequest) {
                    $participant = DB::table('conversation_participants')->where('conversation_id', $conversation->id)->where('user_id', $this->users[$b]->id)->first();
                    if ($participant?->is_request) {
                        $messaging->respondToRequest($conversation, $this->users[$b], 'accept');
                    }
                }
                $say($who, $conversation, $text);
            }

            return $conversation;
        };

        $thread('customer', 'v_decor', [
            ['customer', 'Hi Grace! I love your work at the Eko Hotel wedding. Are you available on the 21st?'],
            ['v_decor', 'Hello Chioma, congratulations! 🎉 Yes, we still have that date open.'],
            ['v_decor', 'How many guests are you expecting, and do you have a colour theme?'],
            ['customer', 'About 300 guests. Thinking champagne gold and blush pink.'],
            ['v_decor', 'Beautiful combo. I will send a proposal with three package options by tomorrow.'],
        ]);
        $thread('customer', 'v_photo', [
            ['customer', 'Good afternoon, do you cover traditional weddings in Enugu?'],
            ['v_photo', 'Yes we do! Travel is billed separately. Want me to share our 2026 rates?'],
            ['customer', 'Please do 🙏'],
        ]);
        $thread('ada', 'customer', [
            ['ada', 'Hi Chioma, thanks for getting VIP tickets for Tolu & Femi\'s wedding!'],
            ['ada', 'Seating opens next week. Any dietary requirements we should know about?'],
            ['customer', 'Thank you! One vegetarian meal please.'],
            ['ada', 'Noted ✅'],
        ]);
        $thread('emeka', 'customer', [
            ['emeka', 'Hey! Saw you are going to Afrobeats Under The Stars. Want to link up there?'],
        ], leaveAsRequest: true);

        // Group chat in the Lagos Brides Club community.
        $communityConversation = DB::table('conversations')->where('community_id', Community::query()->where('name', 'Lagos Brides Club')->value('id'))->value('id');
        $conversation = Conversation::query()->find($communityConversation);
        foreach ([
            ['ada', 'Welcome to the club, ladies! 💕 Introduce yourselves and your wedding dates.'],
            ['customer', 'Hi everyone! Chioma here, wedding in March. Still looking for a makeup artist.'],
            ['v_makeup', 'Hi Chioma! Glam by Teni here. Happy to do a trial session for you.'],
            ['funmi', 'Teni did my sister\'s makeup, she\'s amazing!'],
            ['amaka', 'Does anyone have a good cake vendor in Lagos?'],
        ] as [$who, $text]) {
            $say($who, $conversation, $text);
        }
    }

    // ------------------------------------------------------------- engagement

    private function seedEngagement(): void
    {
        $vendors = app(VendorService::class);
        $reviews = [
            'v_decor' => [['customer', 5, 'Grace and her team transformed the hall. Guests could not stop taking pictures!'], ['funmi', 5, 'Professional, on time and gorgeous flowers.'], ['amaka', 4, 'Lovely work, setup ran a little late but the result was worth it.']],
            'v_photo' => [['emeka', 5, 'Our pictures look like a magazine. Highly recommend.'], ['segun', 5, 'Captured every moment. Same-day edit made my mum cry 😭']],
            'v_catering' => [['ibrahim', 5, 'The jollof was the talk of the party.'], ['ngozi', 4, 'Great food, generous portions.'], ['bola', 5, 'Small chops were 🔥']],
            'v_cakes' => [['zainab', 5, 'Beautiful cake and it tasted even better.']],
            'v_venue' => [['ibrahim', 4, 'Spacious hall, great parking. AC could be stronger.']],
            'v_dj' => [['bola', 5, 'Nobody sat down all night!'], ['david', 4, 'Great mix of old and new.']],
            'v_makeup' => [['customer', 5, 'Flawless beat that lasted from 9am to midnight.']],
            'v_band' => [['segun', 5, 'Took us back to the good old highlife days.']],
        ];
        foreach ($reviews as $vendorKey => $list) {
            foreach ($list as [$reviewer, $rating, $comment]) {
                $vendors->review($this->vendors[$vendorKey], $this->users[$reviewer], $rating, $comment);
            }
        }

        $likers = ['customer', 'emeka', 'funmi', 'ngozi', 'amaka', 'segun', 'zainab'];
        foreach (VendorWork::query()->whereNotIn('vendor_profile_id', $this->designVendorIds)->get() as $i => $work) {
            foreach (array_slice($likers, 0, 2 + $i % 5) as $key) {
                $vendors->toggleLike($work, $this->users[$key]);
            }
        }

        $saves = ['customer' => ['wedding', 'afro', 'fair', 'gala'], 'emeka' => ['afro', 'owambe'], 'funmi' => ['wedding', 'fair'], 'zainab' => ['foodfest', 'owambe', 'afro'], 'segun' => ['comedy', 'afro']];
        foreach ($saves as $key => $eventKeys) {
            foreach ($eventKeys as $eventKey) {
                DB::table('saved_events')->insertOrIgnore(['user_id' => $this->users[$key]->id, 'event_id' => $this->events[$eventKey]->id, 'created_at' => now()->subDays(3)]);
            }
        }
        foreach ($this->events as $event) {
            $event->forceFill(['saves_count' => DB::table('saved_events')->where('event_id', $event->id)->count()])->saveQuietly();
        }
        foreach (['customer' => ['v_decor', 'v_photo', 'v_makeup', 'v_cakes'], 'funmi' => ['v_decor'], 'amaka' => ['v_cakes', 'v_venue']] as $key => $vendorKeys) {
            foreach ($vendorKeys as $vendorKey) {
                DB::table('saved_vendors')->insertOrIgnore(['user_id' => $this->users[$key]->id, 'vendor_profile_id' => $this->vendors[$vendorKey]->id, 'created_at' => now()->subDays(2)]);
            }
        }

        // A couple of extra notifications for the main demo customer.
        $notifications = app(NotificationService::class);
        $wedding = $this->events['wedding'];
        $notifications->notify($this->users['customer'], 'event_reminder', [
            'tokens' => ['event' => $this->events['owambe']->title, 'when' => 'in 9 days'],
            'data' => ['eventId' => $this->events['owambe']->id, 'eventSlug' => $this->events['owambe']->slug],
        ]);
        $notifications->notify($this->users['customer'], 'event_updated', [
            'tokens' => ['event' => $wedding->title],
            'data' => ['eventId' => $wedding->id, 'eventSlug' => $wedding->slug],
        ]);
    }

    // ------------------------------------------------------- platform content

    private function seedPlatform(): void
    {
        $banners = [
            ['Plan your dream wedding', 'Find trusted vendors and sell tickets in minutes.', 13, 'Explore vendors', '/vendors', 'home_hero', 10],
            ['Afrobeats Under The Stars', 'Early bird tickets are almost gone.', 17, 'Get tickets', '/events/'.$this->events['afro']->slug, 'home_hero', 9],
            ['Owambe season is here', 'Discover the biggest parties near you.', 28, 'Browse events', '/events?category=parties', 'events_top', 5],
            ['Go Premium', 'Sub-events, Pluz Planner AI and more.', 33, 'See plans', '/plans', 'home_inline', 3],
        ];
        foreach ($banners as [$title, $subtitle, $photo, $cta, $url, $placement, $priority]) {
            Banner::query()->create([
                'title' => $title, 'subtitle' => $subtitle, 'image_url' => $this->photo($photo, 'banners'), 'cta_label' => $cta,
                'cta_url' => rtrim((string) config('wedpluz.web_origin'), '/').$url, 'placement' => $placement, 'audience' => 'all',
                'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(60), 'is_active' => true, 'priority' => $priority,
            ]);
        }

        foreach ([
            ['customer', 'Wallet top-up not showing', 'payment', 'open', 'high', 'I topped up ₦50,000 but it is taking a while to reflect.'],
            ['emeka', 'Change ticket holder name', 'ticket', 'pending', 'normal', 'Can I change the name on my Owambe Saturday ticket?'],
        ] as [$key, $subject, $category, $status, $priority, $body]) {
            $ticketId = 'sup_'.Str::ulid();
            DB::table('support_tickets')->insert([
                'id' => $ticketId, 'reference' => 'SUP-'.strtoupper(Str::random(6)), 'user_id' => $this->users[$key]->id, 'subject' => $subject,
                'category' => $category, 'status' => $status, 'priority' => $priority, 'source' => 'web', 'created_at' => now()->subHours(6), 'updated_at' => now(),
            ]);
            DB::table('support_messages')->insert(['id' => 'spm_'.Str::ulid(), 'ticket_id' => $ticketId, 'author_user_id' => $this->users[$key]->id, 'body' => $body, 'created_at' => now()->subHours(6)]);
        }

        // One report in the moderation queue.
        $status = StatusPost::query()->where('caption', 'like', 'Confetti%')->first();
        if ($status) {
            app(ReportService::class)->report($this->users['zainab'], ['targetType' => 'status', 'targetId' => $status->id, 'reason' => 'spam', 'details' => 'Posted the same picture several times.']);
        }
    }

    // ------------------------------------------------------------------ admin

    private function seedAdmin(): void
    {
        $email = 'admin@'.self::DOMAIN;
        // A Super Admin with a shared password does not belong on a live server: production
        // uses the real Super Admin created from ADMIN_BOOTSTRAP_EMAIL instead.
        if (app()->isProduction() || AdminUser::query()->where('email', $email)->exists()) {
            return;
        }
        $admin = AdminUser::query()->create([
            'first_name' => 'Demo', 'last_name' => 'Admin', 'email' => $email, 'password' => $this->password, 'status' => 'active',
            'two_factor_secret' => self::ADMIN_TOTP_SECRET, 'two_factor_enabled' => true, 'two_factor_confirmed_at' => now(),
        ]);
        $admin->roles()->attach(AdminRole::query()->where('slug', 'super-admin')->value('id'));
    }

    /** Local: the documented demo password (or DEMO_DATA_PASSWORD). Production: DEMO_DATA_PASSWORD, required. */
    private function resolvePassword(): string
    {
        $configured = (string) config('wedpluz.demo.password');
        if (app()->isProduction() && mb_strlen($configured) < 12) {
            throw new RuntimeException('Set DEMO_DATA_PASSWORD (at least 12 characters) in .env before seeding demo data in production.');
        }

        return $configured !== '' ? $configured : self::PASSWORD;
    }

    // ----------------------------------------------------------------- images

    private function photo(int $index, string $folder): string
    {
        $id = self::PHOTOS[$index];

        return $this->image('photo-'.$id, 'https://images.unsplash.com/photo-'.$id.'?w=1920&q=85&fm=jpg', $folder);
    }

    /** Import a remote image into Cloudinary once (stable public ID), falling back to the source URL. */
    private function image(string $key, string $sourceUrl, string $folder): string
    {
        if (isset($this->imageCache[$key])) {
            return $this->imageCache[$key];
        }
        $cloudinary = app(CloudinaryClient::class);
        $url = $sourceUrl;
        if ($cloudinary->isConfigured()) {
            try {
                $result = $cloudinary->uploadRemote($sourceUrl, 'demo/'.$folder, $key, overwrite: false);
                $url = $cloudinary->url($result['public_id']);
            } catch (Throwable $e) {
                $this->command?->warn('  image import failed for '.$key.': '.$e->getMessage());
            }
        }

        return $this->imageCache[$key] = $url;
    }

    private function printSummary(): void
    {
        $count = fn (string $table) => DB::table($table)->count();
        $this->command?->newLine();
        $this->command?->table(['Data', 'Rows'], [
            ['Users / vendors', $count('users').' / '.$count('vendor_profiles')],
            ['Events (live)', $count('events').' ('.DB::table('events')->where('status', 'live')->count().')'],
            ['Orders / tickets', $count('orders').' / '.$count('tickets')],
            ['Check-ins / settlements', $count('check_ins').' / '.$count('settlements')],
            ['Communities / statuses', $count('communities').' / '.$count('status_posts')],
            ['Messages / notifications', $count('messages').' / '.$count('notifications')],
            ['Ledger entries', $count('ledger_entries')],
        ]);
        $this->command?->info(app()->isProduction()
            ? 'Demo password for every account: the DEMO_DATA_PASSWORD value in .env'
            : 'Demo password for every account: '.$this->password);
        $this->command?->table(['Role', 'Email'], array_filter([
            ['Customer (tickets, wallet, chats)', 'customer@'.self::DOMAIN],
            ['Organiser, Premium (7 events)', 'ada@'.self::DOMAIN],
            ['Organiser, Basic (4 events)', 'tunde@'.self::DOMAIN],
            ['Organiser, unverified (review/draft)', 'kemi@'.self::DOMAIN],
            ['Vendor (Bloom Décor)', 'v_decor@'.self::DOMAIN],
            ['Featured vendor (Shot it moi)', 'd_shot@'.self::DOMAIN],
            app()->isProduction() ? null : ['Admin console (Super Admin)', 'admin@'.self::DOMAIN.'  TOTP secret '.self::ADMIN_TOTP_SECRET],
        ]));
        if (app()->isProduction()) {
            $this->command?->warn('Production: demo accounts cannot withdraw, pay real organisers from their wallet or receive payouts. Remove them before launch: php artisan wedpluz:demo --remove');
        }
        $this->command?->line('Wallet PIN for demo customers: 1234. Promo code: AFRO10 (Afrobeats Under The Stars).');
    }
}
