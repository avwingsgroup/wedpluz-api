<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Upload;
use App\Services\Platform\HealthCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SystemController extends Controller
{
    /** GET /health — liveness/readiness. Never exposes dependency versions. */
    /**
     * GET /health — public liveness/readiness probe for load balancers and uptime
     * monitors. 503 only when a critical dependency (database, Redis, cache) is down;
     * "degraded" (200) when background processing (queue workers, scheduler) stalls.
     * Exposes no versions or internals (contract requirement).
     */
    public function health(HealthCheck $health): JsonResponse
    {
        $summary = $health->summary();

        return response()->json($summary, $health->httpStatus($summary['status']))->header('Cache-Control', 'no-store');
    }

    /**
     * GET /health/details — latencies, queue depths, failed jobs, heartbeats,
     * storage and configured integrations. Requires `X-Health-Token` matching
     * HEALTH_CHECK_TOKEN (open without a token only in the local environment).
     */
    public function healthDetails(Request $request, HealthCheck $health): JsonResponse
    {
        $token = (string) config('wedpluz.health.token');
        $given = (string) ($request->header('X-Health-Token') ?? '');
        $allowed = $token !== '' ? hash_equals($token, $given) : app()->environment('local');
        if (! $allowed) {
            throw ApiException::notFound();
        }
        $details = $health->details();

        return response()->json($details, $health->httpStatus($details['status']))->header('Cache-Control', 'no-store');
    }

    /** Signed, expiring download for private files stored on the local private disk. */
    public function privateFile(Request $request, string $upload): StreamedResponse
    {
        if (! $request->hasValidSignature()) {
            throw ApiException::forbidden('This link has expired.');
        }
        $file = Upload::query()->findOrFail($upload);
        if (! $file->disk_path || ! Storage::disk('private')->exists($file->disk_path)) {
            throw ApiException::notFound();
        }

        return Storage::disk('private')->download($file->disk_path, $file->original_name ?: basename($file->disk_path), [
            'Content-Type' => $file->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
