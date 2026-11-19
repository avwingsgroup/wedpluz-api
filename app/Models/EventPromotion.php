<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventPromotion extends Eloquent
{
    protected $table = 'event_promotions';

    protected $guarded = [];

    protected $primaryKey = 'event_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'commission_percent' => 'float',
            'target_signups' => 'integer',
            'promoter_limit' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }
}
