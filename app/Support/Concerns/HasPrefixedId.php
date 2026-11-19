<?php

namespace App\Support\Concerns;

use Illuminate\Support\Str;

/**
 * Gives a model an opaque, prefixed ULID primary key such as `evt_01J9ZK3T6W8Q...`.
 *
 * The API contract requires opaque string IDs and forbids exposing sequential
 * integers. Storing the prefixed value directly keeps API IDs identical to
 * database keys, so no translation layer is needed in resources or routes.
 *
 * Models declare the prefix with: `protected static string $idPrefix = 'evt';`
 */
trait HasPrefixedId
{
    public static function bootHasPrefixedId(): void
    {
        static::creating(function ($model): void {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = static::newPrefixedId();
            }
        });
    }

    public function initializeHasPrefixedId(): void
    {
        $this->incrementing = false;
        $this->keyType = 'string';
    }

    public static function newPrefixedId(): string
    {
        return static::$idPrefix.'_'.(string) Str::ulid();
    }

    public static function idPrefix(): string
    {
        return static::$idPrefix;
    }
}
