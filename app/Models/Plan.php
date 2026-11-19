<?php

namespace App\Models;

class Plan extends Model
{
    protected $table = 'plans';

    protected static string $idPrefix = 'plan';

    protected function casts(): array
    {
        return [
            'price_monthly' => 'integer',
            'price_yearly' => 'integer',
            'features' => 'array',
            'limits' => 'array',
            'is_popular' => 'boolean',
        ];
    }
}
