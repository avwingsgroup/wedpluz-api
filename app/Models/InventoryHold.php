<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryHold extends Eloquent
{
    protected $table = 'inventory_holds';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'expires_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'converted_at' => 'immutable_datetime',
        ];
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class, 'ticket_type_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(CheckoutQuote::class, 'quote_id');
    }
}
