<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\User;
use App\Support\ListQuery;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

/**
 * Base controller with the contract's response envelopes:
 *   single:  { data, message? }
 *   offset:  { data: [], meta: { page, perPage, total, lastPage } }
 *   cursor:  { data: [], meta: { nextCursor, prevCursor, perPage } }
 */
abstract class Controller
{
    protected function ok(mixed $data, ?string $message = null, int $status = 200): JsonResponse
    {
        $payload = ['data' => $this->resolveData($data)];
        if ($message !== null) {
            $payload['message'] = $message;
        }

        return response()->json($payload, $status);
    }

    protected function created(mixed $data, ?string $message = null): JsonResponse
    {
        return $this->ok($data, $message, 201);
    }

    protected function noContent(): Response
    {
        return response()->noContent();
    }

    /**
     * @param  class-string<JsonResource>|callable|null  $resource
     */
    protected function paginated(LengthAwarePaginator $paginator, string|callable|null $resource = null): JsonResponse
    {
        $items = collect($paginator->items());
        $data = match (true) {
            is_string($resource) => $resource::collection($items)->resolve(request()),
            is_callable($resource) => $items->map($resource)->values()->all(),
            default => $items->values()->all(),
        };

        return response()->json([
            'data' => $data,
            'meta' => ListQuery::meta($paginator),
        ]);
    }

    /**
     * @param  class-string<JsonResource>|callable  $resource
     */
    protected function cursorPaginated(CursorPaginator $paginator, string|callable $resource): JsonResponse
    {
        $items = collect($paginator->items());
        $data = is_string($resource)
            ? $resource::collection($items)->resolve(request())
            : $items->map($resource)->values()->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'nextCursor' => $paginator->nextCursor()?->encode(),
                'prevCursor' => $paginator->previousCursor()?->encode(),
                'perPage' => $paginator->perPage(),
            ],
        ]);
    }

    protected function resolveData(mixed $data): mixed
    {
        if ($data instanceof JsonResource) {
            return $data->resolve(request());
        }

        return $data;
    }

    protected function customer(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }

    protected function optionalCustomer(): ?User
    {
        $user = request()->user();

        return $user instanceof User ? $user : null;
    }

    protected function admin(): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = request()->user();

        return $admin;
    }
}
