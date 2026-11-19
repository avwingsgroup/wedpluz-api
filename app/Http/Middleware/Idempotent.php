<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\IdempotencyKey;
use App\Support\Sql;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency for payment-sensitive writes (api-contracts.md §1.8):
 * - key scoped by user and endpoint, stored >= 24h;
 * - same key + same payload  -> stored response replayed unchanged;
 * - same key + different payload -> 409 CONFLICT;
 * - same key while the first request is still running -> 409 "Request in progress".
 *
 * The key comes from the `Idempotency-Key` header, falling back to the
 * `idempotencyKey` body field that some contract payloads also carry.
 */
class Idempotent
{
    private const LOCK_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $key = trim((string) ($request->header('Idempotency-Key') ?: $request->input('idempotencyKey', '')));

        if ($key === '' || strlen($key) > 191) {
            throw ApiException::validation('An Idempotency-Key header is required for this request.', [
                'idempotencyKey' => ['The idempotency key is required.'],
            ]);
        }

        $endpoint = $request->method().' '.($request->route()?->uri() ?? $request->path());
        $hash = hash('sha256', json_encode($this->normalise($request->except('idempotencyKey'))));
        $request->attributes->set('idempotency_key', $key);

        $record = Sql::insertOrNull(fn () => IdempotencyKey::query()->create([
            'key' => $key,
            'user_id' => $user->getKey(),
            'endpoint' => $endpoint,
            'request_hash' => $hash,
            'locked_until' => now()->addSeconds(self::LOCK_SECONDS),
        ]));

        if ($record === null) {
            return $this->replay($user->getKey(), $endpoint, $key, $hash);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            // Failed attempts must be retryable with the same key.
            $record->delete();
            throw $e;
        }

        if ($response->getStatusCode() >= 500 || in_array($response->getStatusCode(), [409, 429], true)) {
            $record->delete();

            return $response;
        }

        $record->forceFill([
            'response_status' => $response->getStatusCode(),
            'response_body' => $response instanceof JsonResponse ? $response->getData(true) : null,
            'locked_until' => null,
            'completed_at' => now(),
        ])->save();

        return $response;
    }

    private function replay(string $userId, string $endpoint, string $key, string $hash): Response
    {
        $existing = IdempotencyKey::query()
            ->where('user_id', $userId)->where('endpoint', $endpoint)->where('key', $key)->first();

        if ($existing === null) {
            throw ApiException::conflict('Request in progress. Please retry shortly.');
        }
        if (! hash_equals($existing->request_hash, $hash)) {
            throw ApiException::conflict('This idempotency key was already used with a different request.');
        }
        if ($existing->completed_at === null) {
            throw ApiException::conflict('Request in progress. Please retry shortly.');
        }

        return response()->json($existing->response_body, $existing->response_status)
            ->header('Idempotent-Replayed', 'true');
    }

    private function normalise(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(fn ($v) => $this->normalise($v), $value);
        }

        return $value;
    }
}
