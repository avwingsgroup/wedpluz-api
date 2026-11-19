<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class Sql
{
    public static function isUniqueViolation(\Throwable $e): bool
    {
        return $e instanceof QueryException && (($e->errorInfo[0] ?? null) === '23505' || $e->getCode() === '23505');
    }

    /**
     * Run an insert that may hit a unique constraint, inside a savepoint so a
     * violation does not abort an enclosing PostgreSQL transaction.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null null when a unique violation occurred
     */
    public static function insertOrNull(callable $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (QueryException $e) {
            if (self::isUniqueViolation($e)) {
                return null;
            }
            throw $e;
        }
    }
}
