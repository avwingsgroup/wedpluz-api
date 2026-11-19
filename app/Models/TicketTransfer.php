<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketTransfer extends Model
{
    protected $table = 'ticket_transfers';

    protected static string $idPrefix = 'trf';

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }
}
