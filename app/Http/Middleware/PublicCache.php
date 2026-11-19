<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public catalogue reads (/categories, /interests, /plans, /pages/*, /config):
 * `Cache-Control: public, max-age=N` with a strong ETag and 304 support.
 */
class PublicCache
{
    public function handle(Request $request, Closure $next, int $maxAge = 300): Response
    {
        $response = $next($request);

        if (! $request->isMethod('GET') || $response->getStatusCode() !== 200) {
            return $response;
        }

        $etag = '"'.hash('sha256', (string) $response->getContent()).'"';
        $response->headers->set('Cache-Control', 'public, max-age='.$maxAge);
        $response->headers->set('ETag', $etag);

        if (in_array($etag, $request->getETags(), true)) {
            $response->setNotModified();
        }

        return $response;
    }
}
