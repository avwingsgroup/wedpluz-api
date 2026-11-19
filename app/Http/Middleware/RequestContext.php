<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applied to every API request:
 * - forces JSON responses (never HTML or redirects),
 * - adopts or generates `X-Correlation-Id`, adds it to log context and echoes it,
 * - normalises `X-Client-Platform` for `source` fields,
 * - sets baseline security and cache headers.
 */
class RequestContext
{
    public const PLATFORMS = ['web', 'android', 'ios', 'admin'];

    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        $incoming = (string) $request->headers->get('X-Correlation-Id', '');
        $correlationId = preg_match('/^[A-Za-z0-9\-_.]{8,64}$/', $incoming) ? $incoming : (string) Str::uuid();
        $request->attributes->set('correlation_id', $correlationId);

        $platform = strtolower((string) $request->headers->get('X-Client-Platform', 'web'));
        $request->attributes->set('client_platform', in_array($platform, self::PLATFORMS, true) ? $platform : 'web');

        Log::withContext([
            'correlation_id' => $correlationId,
            'route' => $request->path(),
            'client_platform' => $request->attributes->get('client_platform'),
        ]);

        $started = microtime(true);
        $response = $next($request);

        $response->headers->set('X-Correlation-Id', $correlationId);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'DENY');

        if (! $response->headers->has('Cache-Control') || $response->headers->get('Cache-Control') === 'no-cache, private') {
            $response->headers->set('Cache-Control', $request->is('api/v1/admin/*') ? 'no-store' : 'private, no-store');
        }

        if ($response->getStatusCode() >= 400) {
            Log::info('api.response', [
                'status' => $response->getStatusCode(),
                'method' => $request->method(),
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'actor' => $request->user()?->getKey(),
            ]);
        }

        return $response;
    }

    public static function source(?Request $request = null): string
    {
        $platform = ($request ?? request())->attributes->get('client_platform', 'web');

        return in_array($platform, ['web', 'android', 'ios'], true) ? $platform : 'web';
    }
}
