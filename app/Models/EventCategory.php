<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class EventCategory extends Model
{
    protected $table = 'event_categories';

    protected static string $idPrefix = 'cat';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'requires_review' => 'boolean',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'category_id');
    }
}
