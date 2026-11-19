<?php

namespace App\Services\Checkout;

use App\Models\TicketType;
use Illuminate\Support\Facades\DB;

/**
 * Stock = quantity - sold - active holds. A hold is active until it expires,
 * is released, or is converted when its order is paid. Callers lock ticket
 * type rows (FOR UPDATE) before checking and holding, so concurrent quotes
 * serialise per ticket type and never oversell.
 */
class Inventory
{
    /** @param list<string> $ticketTypeIds @return array<string,int> */
    public function activeHolds(array $ticketTypeIds, ?string $excludeQuoteId = null): array
    {
        return DB::table('inventory_holds')
            ->whereIn('ticket_type_id', $ticketTypeIds)
            ->whereNull('released_at')->whereNull('converted_at')
            ->where('expires_at', '>', now())
            ->when($excludeQuoteId, fn ($q) => $q->where('quote_id', '!=', $excludeQuoteId))
            ->selectRaw('ticket_type_id, sum(quantity) as qty')->groupBy('ticket_type_id')
            ->pluck('qty', 'ticket_type_id')->map(fn ($q) => (int) $q)->all();
    }

    public function remaining(TicketType $type, int $held): int
    {
        return max(0, $type->quantity - $type->sold - $held);
    }

    /**
     * Admitted people for an event: issued tickets plus active holds
     * (guestLimit caps tickets + additional guests across all types).
     */
    public function admittedCount(string $eventId): int
    {
        $tickets = DB::table('tickets')->where('event_id', $eventId)->whereIn('status', ['valid', 'checked_in'])->count();
        $holds = (int) DB::table('inventory_holds')
            ->join('ticket_types', 'ticket_types.id', '=', 'inventory_holds.ticket_type_id')
            ->where('ticket_types.event_id', $eventId)
            ->whereNull('inventory_holds.released_at')->whereNull('inventory_holds.converted_at')
            ->where('inventory_holds.expires_at', '>', now())
            ->sum('inventory_holds.quantity');

        return $tickets + $holds;
    }

    /**
     * Atomic conditional increment; returns false if it would exceed quantity.
     * UPDATE ... SET sold = sold + n WHERE id = ? AND quantity - sold >= n
     */
    public function incrementSold(string $ticketTypeId, int $quantity): bool
    {
        return DB::table('ticket_types')->where('id', $ticketTypeId)
            ->whereRaw('quantity - sold >= ?', [$quantity])
            ->update(['sold' => DB::raw('sold + '.(int) $quantity), 'updated_at' => now()]) === 1;
    }

    public function decrementSold(string $ticketTypeId, int $quantity): void
    {
        DB::table('ticket_types')->where('id', $ticketTypeId)
            ->update(['sold' => DB::raw('greatest(0, sold - '.(int) $quantity.')'), 'updated_at' => now()]);
    }
}
