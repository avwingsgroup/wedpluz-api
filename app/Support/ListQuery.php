<?php

namespace App\Support;

use App\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * Implements the list conventions from api-contracts.md §1.4:
 * 1-based `page`, `perPage` default 20 clamped to 100, `sort` keys validated
 * against an allow-list (unknown keys -> 422), `order` asc|desc, `search`
 * ignored below 2 characters, inclusive `dateFrom`/`dateTo` in the platform
 * timezone, and `all` meaning "no filter" for enum filters.
 */
class ListQuery
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    public function __construct(private readonly Request $request) {}

    public static function from(Request $request): self
    {
        return new self($request);
    }

    public function page(): int
    {
        return max(1, (int) $this->request->query('page', 1));
    }

    public function perPage(int $default = self::DEFAULT_PER_PAGE): int
    {
        $perPage = (int) $this->request->query('perPage', $default);

        return max(1, min(self::MAX_PER_PAGE, $perPage ?: $default));
    }

    public function search(): ?string
    {
        $search = trim((string) $this->request->query('search', ''));

        return mb_strlen($search) >= 2 ? $search : null;
    }

    /** Escaped pattern for ILIKE. */
    public function searchPattern(): ?string
    {
        $search = $this->search();

        return $search === null ? null : '%'.addcslashes($search, '%_\\').'%';
    }

    /** Enum filter value, or null when absent or `all`. */
    public function filter(string $key): ?string
    {
        $value = $this->request->query($key);
        if ($value === null || $value === '' || $value === 'all' || $value === 'any') {
            return null;
        }

        return (string) $value;
    }

    public function bool(string $key): ?bool
    {
        $value = $this->request->query($key);
        if ($value === null || $value === '' || $value === 'all') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    public function dateFrom(string $key = 'dateFrom'): ?CarbonImmutable
    {
        $value = $this->request->query($key);

        return $value ? $this->parseDate((string) $value, $key)->startOfDay()->utc() : null;
    }

    public function dateTo(string $key = 'dateTo'): ?CarbonImmutable
    {
        $value = $this->request->query($key);

        return $value ? $this->parseDate((string) $value, $key)->endOfDay()->utc() : null;
    }

    /**
     * Apply validated sort. $allowed maps API sort keys to SQL columns or expressions.
     *
     * @param  array<string, string>  $allowed
     */
    public function applySort(EloquentBuilder $query, array $allowed, string $defaultKey, string $defaultOrder = 'desc'): EloquentBuilder
    {
        $key = (string) $this->request->query('sort', $defaultKey);
        if (! array_key_exists($key, $allowed)) {
            throw ApiException::validation('Unknown sort field.', ['sort' => ['The selected sort is invalid.']]);
        }
        $order = strtolower((string) $this->request->query('order', $defaultOrder));
        if (! in_array($order, ['asc', 'desc'], true)) {
            throw ApiException::validation('Unknown sort order.', ['order' => ['The order must be asc or desc.']]);
        }

        $column = $allowed[$key];
        if (str_contains($column, '(') || str_contains($column, ' ')) {
            $query->orderByRaw($column.' '.$order.' nulls last');
        } else {
            $query->orderBy($column, $order);
        }

        return $query;
    }

    public function paginate(EloquentBuilder $query, int $defaultPerPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        return $query->paginate($this->perPage($defaultPerPage), ['*'], 'page', $this->page());
    }

    /** @return array{page:int, perPage:int, total:int, lastPage:int} */
    public static function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'page' => $paginator->currentPage(),
            'perPage' => $paginator->perPage(),
            'total' => $paginator->total(),
            'lastPage' => max(1, $paginator->lastPage()),
        ];
    }

    private function parseDate(string $value, string $key): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, Platform::timezone())
                ?: throw new \InvalidArgumentException;
        } catch (\Throwable) {
            throw ApiException::validation('Invalid date.', [$key => ['Use the YYYY-MM-DD format.']]);
        }
    }
}
