<?php

namespace App\Exceptions;

use App\Integrations\Support\IntegrationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders every API failure as ApiErrorBody (error-handling.md §1):
 * `{ message, code, errors?, correlationId }`. Messages are always safe for end
 * users; internal details never leave the server outside local debugging.
 */
class ApiExceptionRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        [$status, $code, $message, $errors, $headers] = $this->map($e);

        $body = ['message' => $message, 'code' => $code];
        if ($errors !== []) {
            $body['errors'] = $errors;
        }
        $correlationId = $request->attributes->get('correlation_id');
        if ($correlationId) {
            $body['correlationId'] = $correlationId;
            $headers['X-Correlation-Id'] = $correlationId;
        }
        if ($status >= 500 && config('app.debug') && app()->environment('local')) {
            $body['debug'] = ['exception' => $e::class, 'message' => $e->getMessage()];
        }

        $headers['Cache-Control'] = 'no-store';

        return new JsonResponse($body, $status, $headers);
    }

    /** @return array{0:int,1:string,2:string,3:array<string,list<string>>,4:array<string,string>} */
    private function map(Throwable $e): array
    {
        return match (true) {
            $e instanceof ApiException => [$e->status, $e->errorCode, $e->getMessage(), $e->errors, $e->headers],
            $e instanceof ValidationException => [422, 'VALIDATION_ERROR', 'Please check the highlighted fields.', $e->errors(), []],
            $e instanceof AuthenticationException => [401, 'UNAUTHENTICATED', 'Your session has expired. Please sign in again.', [], []],
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => [403, 'FORBIDDEN', 'You are not allowed to perform this action.', [], []],
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [404, 'NOT_FOUND', 'The requested resource was not found.', [], []],
            $e instanceof MethodNotAllowedHttpException => [405, 'METHOD_NOT_ALLOWED', 'This method is not supported for this endpoint.', [], $e->getHeaders()],
            $e instanceof ThrottleRequestsException => $this->throttle($e),
            $e instanceof PostTooLargeException => [413, 'VALIDATION_ERROR', 'The uploaded file is too large.', ['file' => ['The file is too large.']], []],
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 503 => [503, 'SERVER_ERROR', 'Wedpluz is undergoing maintenance. Please try again shortly.', [], $e->getHeaders()],
            $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500 => [$e->getStatusCode(), 'VALIDATION_ERROR', $e->getMessage() ?: 'The request could not be processed.', [], $e->getHeaders()],
            // Upstream provider failures (error-handling.md: 502/503 SERVER_ERROR). Provider
            // messages may carry internals, so clients get a generic message only.
            $e instanceof IntegrationException && ($e->providerCode === 'not_configured' || $e->transient) => [503, 'SERVER_ERROR', 'This service is temporarily unavailable. Please try again shortly.', [], ['Retry-After' => '30']],
            $e instanceof IntegrationException => [502, 'SERVER_ERROR', 'A partner service could not complete this request. Please try again.', [], []],
            default => [500, 'SERVER_ERROR', 'Something went wrong on our side. Please try again.', [], []],
        };
    }

    private function throttle(ThrottleRequestsException $e): array
    {
        $headers = $e->getHeaders();
        $retry = (int) ($headers['Retry-After'] ?? 60);

        return [429, 'RATE_LIMITED', 'Too many requests. Please try again in '.ApiException::humanSeconds($retry).'.', [], ['Retry-After' => (string) $retry]];
    }
}
