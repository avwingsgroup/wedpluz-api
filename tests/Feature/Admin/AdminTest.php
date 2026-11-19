<?php

use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\SecurityLog;
use App\Models\Settlement;
use App\Services\Admin\TwoFactorService;
use Illuminate\Support\Facades\Http;

it('requires password then a TOTP code to sign in', function () {
    $admin = $this->adminUser(null, ['email' => 'ops@wedpluz.test']);

    $this->postJson('/api/v1/admin/auth/login', ['email' => 'ops@wedpluz.test', 'password' => 'AdminPass123'])
        ->assertOk()->assertJsonPath('data.requiresTwoFactor', true)->assertJsonPath('data.tokens', null);

    $this->postJson('/api/v1/admin/auth/login', ['email' => 'ops@wedpluz.test', 'password' => 'AdminPass123', 'otp' => '000000'])
        ->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');

    $code = app(TwoFactorService::class)->currentCode('JBSWY3DPEHPK3PXP');
    $token = $this->postJson('/api/v1/admin/auth/login', ['email' => 'ops@wedpluz.test', 'password' => 'AdminPass123', 'otp' => $code])
        ->assertOk()->assertJsonPath('data.admin.id', $admin->id)->json('data.tokens.accessToken');

    // A TOTP step cannot be replayed.
    $this->postJson('/api/v1/admin/auth/login', ['email' => 'ops@wedpluz.test', 'password' => 'AdminPass123', 'otp' => $code])->assertStatus(422);

    $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/admin/me')->assertOk()->assertJsonPath('data.email', 'ops@wedpluz.test');
    expect(SecurityLog::query()->where('event', 'login_success')->count())->toBe(1)
        ->and(SecurityLog::query()->where('event', 'login_failed')->count())->toBeGreaterThanOrEqual(2);
});

it('rejects customer tokens on admin routes and admin tokens on customer routes', function () {
    $this->asCustomer();
    $this->getJson('/api/v1/admin/me')->assertStatus(401);

    $this->asAdmin();
    $this->getJson('/api/v1/me')->assertStatus(401);
});

it('denies missing permissions with 403 and logs the denial', function () {
    $this->asAdmin(null, ['users.view']);

    $this->getJson('/api/v1/admin/users')->assertOk();
    $this->getJson('/api/v1/admin/finance/summary')->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');

    expect(SecurityLog::query()->where('event', 'permission_denied')->exists())->toBeTrue();
});

it('suspends a user, revokes their tokens and writes an audit entry', function () {
    $user = $this->customer();
    $this->asCustomer($user);
    $this->asAdmin(null, ['users.view', 'users.suspend']);

    $this->postJson("/api/v1/admin/users/{$user->id}/suspend", ['reason' => 'Fraudulent tickets'])
        ->assertOk()->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.suspendedReason', 'Fraudulent tickets');

    expect($user->tokens()->count())->toBe(0);
    $audit = AuditLog::query()->where('action', 'user.suspended')->firstOrFail();
    expect($audit->target_id)->toBe($user->id)->and($audit->after['reason'])->toBe('Fraudulent tickets');
});

it('approves events in the review queue and makes them discoverable', function () {
    $event = $this->liveEvent(attributes: ['status' => 'pending_review', 'submitted_at' => now()]);
    $this->getJson('/api/v1/events')->assertJsonPath('meta.total', 0);

    $this->asAdmin(null, ['events.view', 'events.moderate']);
    $this->postJson("/api/v1/admin/events/{$event->id}/approve")->assertOk()->assertJsonPath('data.status', 'live');

    app('auth')->forgetGuards();
    $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/events')->assertJsonPath('meta.total', 1);
});

it('enforces four-eyes payout approval with a fresh 2FA code', function () {
    $this->configurePaystack();
    $organizer = $this->customer(['identity_status' => 'verified']);
    $event = $this->liveEvent($organizer, attributes: ['status' => 'completed']);
    BankAccount::query()->create([
        'user_id' => $organizer->id, 'bank_code' => '058', 'bank_name' => 'GTBank', 'account_number_encrypted' => '0123456789',
        'account_number_hash' => 'h', 'account_last4' => '6789', 'account_name' => 'ADA', 'is_default' => true, 'verified_at' => now(),
    ]);
    BankAccount::query()->update(['created_at' => now()->subDays(5), 'updated_at' => now()->subDays(5)]);
    $settlement = Settlement::query()->create([
        'reference' => 'ST-TEST1', 'organizer_user_id' => $organizer->id, 'event_id' => $event->id, 'gross_sales' => 1000000,
        'net_payable' => 900000, 'currency' => 'NGN', 'status' => 'ready', 'eligible_at' => now()->subDay(),
    ]);

    $creator = $this->asAdmin(null, ['finance.settlements', 'finance.payouts.approve', 'finance.view']);
    $payout = $this->postJson('/api/v1/admin/finance/payouts', ['settlementIds' => [$settlement->id]])->assertOk()->assertJsonPath('data.status', 'requested')->json('data');

    // The creator cannot approve their own payout.
    $code = app(TwoFactorService::class)->currentCode('JBSWY3DPEHPK3PXP');
    $this->postJson("/api/v1/admin/finance/payouts/{$payout['id']}/approve", ['otp' => $code])->assertStatus(403);

    $approver = $this->adminUser(['finance.payouts.approve', 'finance.view'], ['two_factor_secret' => 'KRSXG5CTMVRXEZLU']);
    $this->asAdmin($approver);
    $this->postJson("/api/v1/admin/finance/payouts/{$payout['id']}/approve", ['otp' => '123456'])->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');

    Http::fake([
        'api.paystack.co/transferrecipient' => Http::response(['status' => true, 'data' => ['recipient_code' => 'RCP_9']]),
        'api.paystack.co/transfer' => Http::response(['status' => true, 'data' => ['status' => 'success', 'transfer_code' => 'TRF_9']]),
    ]);
    $this->postJson("/api/v1/admin/finance/payouts/{$payout['id']}/approve", ['otp' => app(TwoFactorService::class)->currentCode('KRSXG5CTMVRXEZLU')])
        ->assertOk()->assertJsonPath('data.approvedBy.id', $approver->id);

    expect($settlement->refresh()->status->value)->toBe('paid');
});

it('lists the permission catalogue and blocks granting permissions the admin lacks', function () {
    $this->asAdmin(null, ['roles.manage']);
    $this->getJson('/api/v1/admin/permissions')->assertOk()->assertJsonCount(32, 'data');
    $this->postJson('/api/v1/admin/roles', ['name' => 'Sneaky', 'permissions' => ['finance.payouts.approve']])->assertStatus(403);
    $this->postJson('/api/v1/admin/roles', ['name' => 'Role managers', 'permissions' => ['roles.manage']])->assertCreated();
});

it('runs a report and returns columns, rows and totals', function () {
    $this->liveEvent();
    $this->asAdmin(null, ['reports.view']);

    $this->postJson('/api/v1/admin/reports/event_performance/run', ['from' => now()->subYear()->toDateString(), 'to' => now()->addYear()->toDateString(), 'groupBy' => 'category'])
        ->assertOk()->assertJsonPath('data.groupBy', 'category')->assertJsonPath('data.totals.events', 1);
});

it('serves the executive dashboard', function () {
    $this->liveEvent();
    $this->asAdmin(null, ['dashboard.view']);

    $this->getJson('/api/v1/admin/dashboard')->assertOk()
        ->assertJsonStructure(['data' => ['users' => ['total', 'newInPeriod' => ['value', 'previous', 'changePercent', 'trend']], 'events', 'tickets', 'finance', 'gatewayHealth', 'alerts', 'recentActivity']]);
});
