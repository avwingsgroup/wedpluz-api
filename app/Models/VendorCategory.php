<?php

namespace App\Models;

class VendorCategory extends Model
{
    protected $table = 'vendor_categories';

    protected static string $idPrefix = 'vct';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
