<?php

namespace App\Providers;

use App\Exceptions\ApiException;
use App\Http\Controllers\SeoController;
use App\Models\AdminUser;
use App\Models\CommunityInvite;
use App\Models\Event;
use App\Models\EventManager;
use App\Models\EventRequest;
use App\Models\EventVendorAssignment;
use App\Models\Message;
use App\Models\Order;
use App\Models\PersonalAccessToken;
use App\Models\StatusPost;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Models\VendorProfile;
use App\Models\VendorWork;
use App\Models\WalletTransaction;
use App\Services\Events\EventAccess;
use App\Services\Platform\HealthCheck;
use App\Services\Platform\SettingsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Request-scoped so the in-memory memo never leaks between requests (Octane-safe).
        $this->app->scoped(SettingsService::class);
        $this->app->scoped(EventAccess::class);

        $this->app->register(IntegrationServiceProvider::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::automaticallyEagerLoadRelationships(false);

        // Sitemaps regenerate after any public-facing change to events or vendors.
        foreach ([Event::class, VendorProfile::class] as $model) {
            $model::saved(fn () => SeoController::invalidate());
            $model::deleted(fn () => SeoController::invalidate());
        }

        // Queue workers report liveness for /health (throttled to one write per 15 s per worker).
        Queue::looping(fn (Looping $event) => HealthCheck::beatWorker($event->connectionName));

        Relation::enforceMorphMap([
            'user' => User::class,
            'admin_user' => AdminUser::class,
            'order' => Order::class,
            'wallet_transaction' => WalletTransaction::class,
            'subscription_payment' => SubscriptionPayment::class,
            'work' => VendorWork::class,
            'status' => StatusPost::class,
            'message' => Message::class,
            'event_manager' => EventManager::class,
            'event_vendor_assignment' => EventVendorAssignment::class,
            'community_invite' => CommunityInvite::class,
            'event_request' => EventRequest::class,
        ]);

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Runs before Sanctum bumps last_used_at, so admin idle timeout is accurate.
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid): bool {
            if (! $isValid) {
                return false;
            }
            if ($token->tokenable_type === 'admin_user') {
                $idle = (int) config('wedpluz.auth.admin_idle_timeout_minutes', 30);
                if ($idle > 0 && $token->last_used_at !== null && $token->last_used_at->lt(now()->subMinutes($idle))) {
                    $token->delete();

                    return false;
                }
            }

            return true;
        });

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        $tooMany = fn (Request $request, array $headers) => throw ApiException::rateLimited((int) ($headers['Retry-After'] ?? 60));

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by('login|'.$request->ip().'|'.mb_strtolower((string) $request->input('email')))
            ->response($tooMany));

        RateLimiter::for('otp', fn (Request $request) => Limit::perMinutes(10, 3)
            ->by('otp|'.mb_strtolower((string) $request->input('email', $request->ip())))
            ->response($tooMany));

        RateLimiter::for('suggestions', fn (Request $request) => Limit::perMinute(30)->by('sugg|'.$request->ip())->response($tooMany));

        RateLimiter::for('customer', fn (Request $request) => Limit::perMinute(120)
            ->by('cust|'.($request->user()?->getKey() ?? $request->ip()))
            ->response($tooMany));

        RateLimiter::for('admin', fn (Request $request) => Limit::perMinute(300)
            ->by('adm|'.($request->user()?->getKey() ?? $request->ip()))
            ->response($tooMany));

        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(240)->by('pub|'.$request->ip())->response($tooMany));

        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(600)->by('whk|'.$request->ip()));
    }
}
