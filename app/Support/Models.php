<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Collection;

final class Models
{
    /** Wrap any iterable of models in an Eloquent collection (enables loadMissing). */
    public static function collect(iterable $models): Collection
    {
        if ($models instanceof Collection) {
            return $models;
        }

        return new Collection(is_array($models) ? array_values($models) : array_values(iterator_to_array($models, false)));
    }
}
