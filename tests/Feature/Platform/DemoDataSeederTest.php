<?php

use Database\Seeders\DemoDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;

it('seeds a consistent demo dataset through the real services', function () {
    $this->seed(ReferenceDataSeeder::class);
    $this->seed(DemoDataSeeder::class);

    expect(DB::table('users')->where('email', 'like', '%@'.DemoDataSeeder::DOMAIN)->count())->toBe(28)
        ->and(DB::table('events')->where('status', 'live')->count())->toBe(10)
        ->and(DB::table('orders')->where('status', 'paid')->count())->toBe(40)
        ->and(DB::table('settlements')->count())->toBe(1)
        ->and(DB::table('check_ins')->count())->toBeGreaterThan(0);

    // Ledger balances and counters match what was issued.
    $sums = DB::table('ledger_entries')->selectRaw("sum(case when direction='debit' then amount else 0 end) d, sum(case when direction='credit' then amount else 0 end) c")->first();
    expect((int) $sums->d)->toBe((int) $sums->c)
        ->and((int) DB::table('ticket_types')->sum('sold'))->toBe((int) DB::table('events')->sum('tickets_sold'));

    // Idempotent.
    $this->seed(DemoDataSeeder::class);
    expect(DB::table('orders')->count())->toBe(40);

    $this->postJson('/api/v1/auth/login', ['email' => 'customer@'.DemoDataSeeder::DOMAIN, 'password' => DemoDataSeeder::PASSWORD])->assertOk();
});
