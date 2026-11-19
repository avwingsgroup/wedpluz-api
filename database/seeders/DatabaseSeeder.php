<?php

namespace Database\Seeders;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Services\Admin\TwoFactorService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);
        $this->bootstrapSuperAdmin();
    }

    /**
     * Creates the first Super Admin from ADMIN_BOOTSTRAP_EMAIL when no admin
     * exists. The TOTP secret is printed once so it can be added to an
     * authenticator app; 2FA is mandatory for every admin login.
     */
    private function bootstrapSuperAdmin(): void
    {
        $email = env('ADMIN_BOOTSTRAP_EMAIL');
        if (! $email || AdminUser::query()->exists()) {
            return;
        }

        $password = env('ADMIN_BOOTSTRAP_PASSWORD') ?: Str::password(20);
        $twoFactor = app(TwoFactorService::class);
        $secret = $twoFactor->generateSecret();

        $admin = AdminUser::query()->create([
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => mb_strtolower($email),
            'password' => $password,
            'status' => 'active',
            'two_factor_secret' => $secret,
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $admin->roles()->attach(AdminRole::query()->where('slug', 'super-admin')->value('id'));

        $this->command?->warn('Super Admin created: '.$admin->email);
        if (! env('ADMIN_BOOTSTRAP_PASSWORD')) {
            $this->command?->warn('Generated password (store it now): '.$password);
        }
        $this->command?->warn('TOTP secret (add to an authenticator app now): '.$secret);
        $this->command?->line($twoFactor->provisioningUri($admin, $secret));
    }
}
