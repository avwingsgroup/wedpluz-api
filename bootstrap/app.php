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

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
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
