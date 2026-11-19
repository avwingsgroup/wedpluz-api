<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventSchedule extends Model
{
    protected $table = 'event_schedules';

    protected static string $idPrefix = 'sch';

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'venue' => 'array',
            'online' => 'array',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function hallLayout(): BelongsTo
    {
        return $this->belongsTo(HallLayout::class, 'hall_layout_id');
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(PlannerMenu::class, 'menu_id');
    }
}
