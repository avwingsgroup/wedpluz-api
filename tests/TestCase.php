<?php

namespace Tests;

use App\Enums\AdminPermission;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\EventPromotion;
use App\Models\EventSchedule;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Auth\TokenService;
use App\Services\Events\EventService;
use App\Services\Users\UserProvisioner;
use App\Support\Codes;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // No test may reach a real provider. Tests that need a provider fake it explicitly.
        Http::preventStrayRequests();
        Mail::fake();
        config(['wedpluz.realtime.enabled' => false]);
    }

    /** Create a verified, onboarded customer. */
    public function customer(array $attributes = []): User
    {
        return app(UserProvisioner::class)->create(array_merge([
            'first_name' => 'Ada',
            'last_name' => 'Okafor',
            'email' => 'user'.uniqid().'@example.com',
            'password' => 'secret123',
            'date_of_birth' => '1995-05-05',
            'email_verified_at' => now(),
            'profile_completed' => true,
            'interests_completed' => true,
            'username' => 'u'.substr(uniqid(), -10),
        ], $attributes));
    }

    /** Authenticate subsequent requests with a real customer bearer token. */
    public function asCustomer(?User $user = null): User
    {
        $user ??= $this->customer();
        $tokens = app(TokenService::class)->issueCustomer($user, 'test');
        $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken']);
        app('auth')->forgetGuards();

        return $user;
    }

    /** @param list<string>|null $permissions null = all permissions (Super Admin) */
    public function adminUser(?array $permissions = null, array $attributes = []): AdminUser
    {
        $admin = AdminUser::query()->create(array_merge([
            'first_name' => 'Grace',
            'last_name' => 'Admin',
            'email' => 'admin'.uniqid().'@wedpluz.test',
            'password' => 'AdminPass123',
            'status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ], $attributes));

        $role = AdminRole::query()->create([
            'name' => 'Test role '.uniqid(),
            'slug' => 'test-'.uniqid(),
            'description' => 'test',
        ]);
        $role->syncPermissions($permissions ?? AdminPermission::values());
        $admin->roles()->attach($role->id);

        return $admin;
    }

    public function asAdmin(?AdminUser $admin = null, ?array $permissions = null): AdminUser
    {
        $admin ??= $this->adminUser($permissions);
        $tokens = app(TokenService::class)->issueAdmin($admin);
        $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken']);
        app('auth')->forgetGuards();

        return $admin;
    }

    public function fakeQueue(): void
    {
        Queue::fake();
    }

    /**
     * A live, public event with one schedule and the given ticket types.
     *
     * @param  list<array{name?:string, price?:int, quantity?:int, per_order_limit?:int, seat_selection?:bool}>  $types
     */
    public function liveEvent(?User $organizer = null, array $types = [['name' => 'Regular', 'price' => 500000, 'quantity' => 100]], array $attributes = []): Event
    {
        $organizer ??= $this->customer(['identity_status' => 'verified']);
        $category = EventCategory::query()->firstOrCreate(['slug' => 'weddings'], ['name' => 'Weddings', 'icon' => 'heart']);
        $event = Event::query()->create(array_merge([
            'organizer_user_id' => $organizer->id,
            'category_id' => $category->id,
            'title' => 'Luxury Wedding Gala',
            'slug' => 'luxury-wedding-gala-'.strtolower(Codes::random(6)),
            'description' => 'A night to remember.',
            'status' => 'live',
            'visibility' => 'public',
            'currency' => 'NGN',
            'published_at' => now(),
        ], $attributes));
        EventPromotion::query()->create(['event_id' => $event->id, 'enabled' => false, 'commission_percent' => 10]);
        EventSchedule::query()->create([
            'event_id' => $event->id,
            'starts_at' => $attributes['starts_at'] ?? now()->addDays(10),
            'ends_at' => ($attributes['starts_at'] ?? now()->addDays(10))->copy()->addHours(6),
            'attendance_mode' => 'physical',
            'venue' => ['name' => 'Eko Hotel', 'address' => 'Victoria Island', 'city' => 'Lagos', 'state' => 'Lagos', 'countryCode' => 'NG', 'lat' => 6.42, 'lng' => 3.43],
            'city' => 'Lagos',
        ]);
        foreach ($types as $i => $type) {
            TicketType::query()->create([
                'event_id' => $event->id,
                'name' => $type['name'] ?? 'Ticket '.$i,
                'price' => $type['price'] ?? 500000,
                'currency' => 'NGN',
                'quantity' => $type['quantity'] ?? 100,
                'per_order_limit' => $type['per_order_limit'] ?? 10,
                'seat_selection' => $type['seat_selection'] ?? false,
                'is_active' => true,
                'sort_order' => $i,
            ]);
        }
        app(EventService::class)->recalculate($event);

        return $event->refresh();
    }

    /** Paystack configured with a test secret. */
    public function configurePaystack(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_secret', 'services.paystack.base_url' => 'https://api.paystack.co']);
    }
}
