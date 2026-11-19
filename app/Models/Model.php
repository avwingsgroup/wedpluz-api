<?php

namespace App\Models;

use App\Support\Concerns\HasPrefixedId;
use Illuminate\Database\Eloquent\Model as Eloquent;

/**
 * Base model: prefixed ULID keys, timestamptz-friendly date format.
 *
 * Mass assignment is open (`$guarded = []`) because request data never reaches
 * models directly: controllers pass validated FormRequest data to services,
 * which map each field explicitly. Do not call `fill($request->all())`.
 */
abstract class Model extends Eloquent
{
    use HasPrefixedId;

    protected $guarded = [];

    protected static string $idPrefix = 'obj';

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:sP';
    }
}
