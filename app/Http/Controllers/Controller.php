<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\User;
use App\Services\Platform\SlugHistory;
use App\Support\ListQuery;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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
    /**
     * 301 from a renamed event's or vendor's old slug to its current one (same
     * path, last segment swapped), only when the record is publicly visible;
     * otherwise null and the caller answers 404 as usual.
     *
     * @param  class-string<Model>  $modelClass
     */
    protected function movedTo(string $type, string $slug, string $modelClass): ?RedirectResponse
    {
        $id = app(SlugHistory::class)->resolve($type, $slug);
        $target = $id ? $modelClass::query()->find($id) : null;
        if ($target === null || ! self::publiclyVisible($type, $target)) {
            return null;
        }
        $request = request();
        $url = preg_replace('#/'.preg_quote(rawurlencode($slug), '#').'$#', '/'.rawurlencode((string) $target->slug), $request->url());
        $query = $request->getQueryString();

        return redirect()->to($url.($query ? '?'.$query : ''), 301)->header('Cache-Control', 'public, max-age=3600');
    }

    public static function publiclyVisible(string $type, Model $record): bool
    {
        $value = fn ($v) => $v instanceof \BackedEnum ? $v->value : $v;

        return $type === 'event'
            ? $value($record->visibility) === 'public' && in_array($value($record->status), ['live', 'completed'], true)
            : $value($record->status) === 'active';
    }

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
