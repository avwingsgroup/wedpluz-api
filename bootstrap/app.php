<?php

use App\Exceptions\ApiException;
use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\AuditAdminWrites;
use App\Http\Middleware\EnsureAudience;
use App\Http\Middleware\EnsureEmailVerified;
use App\Http\Middleware\Idempotent;
use App\Http\Middleware\OptimizeImageUrls;
use App\Http\Middleware\OptionalCustomerAuth;
use App\Http\Middleware\PublicCache;
use App\Http\Middleware\RequestContext;
use App\Http\Middleware\RequireAdminPermission;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Route;

// Thread-safe SAPIs (e.g. Apache mod_php on Windows, mpm_winnt): putenv()/getenv()
// are process-wide and not thread-safe, so concurrent requests can lose .env values
// (APP_ENV falling back to "production", CORS origins disappearing). Keep env values
// in the per-thread $_SERVER/$_ENV only. CLI, PHP-FPM and FastCGI are unaffected.
if (ZEND_THREAD_SAFE && PHP_SAPI !== 'cli') {
    Env::disablePutenv();
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        // Sitemaps and link-preview pages: outside /api/v1, no session/cookies/CSRF.
        then: fn () => Route::middleware('throttle:public')->group(base_path('routes/seo.php')),
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api/v1', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->api(prepend: [RequestContext::class]);
        // Every image URL in API responses is delivered through Cloudinary (format, quality, size).
        $middleware->api(append: [OptimizeImageUrls::class]);
        $middleware->alias([
            'audience' => EnsureAudience::class,
            'auth.optional' => OptionalCustomerAuth::class,
            'permission' => RequireAdminPermission::class,
            'admin.audit' => AuditAdminWrites::class,
            'idempotent' => Idempotent::class,
            'verified.email' => EnsureEmailVerified::class,
            'cache.public' => PublicCache::class,
        ]);
        // Never redirect API clients to a login page.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(new ApiExceptionRenderer);
        // Domain errors are expected outcomes, not incidents.
        $exceptions->dontReport([ApiException::class]);
        $exceptions->dontFlash(['password', 'passwordConfirmation', 'currentPassword', 'pin', 'pinConfirmation', 'currentPin', 'otp', 'otpCode', 'code', 'idNumber', 'accountNumber']);
    })->create();
