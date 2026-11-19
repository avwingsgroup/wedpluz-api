<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Base request. Field keys are the contract's camelCase request names, so
 * 422 `errors` keys match the request body (e.g. `attendee.email`,
 * `lines.0.quantity`). Attribute names in messages are humanised
 * ("date of birth", not "dateOfBirth").
 */
abstract class ApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function attributes(): array
    {
        $names = [];
        foreach (array_keys($this->rules()) as $key) {
            $last = Str::afterLast(str_replace('.*', '', $key), '.');
            $names[$key] = strtolower(Str::snake($last, ' '));
        }

        return $names + $this->customAttributes();
    }

    /** @return array<string,string> */
    protected function customAttributes(): array
    {
        return [];
    }
}
