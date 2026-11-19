<?php

namespace App\Services\Checkout;

use App\Enums\EventStatus;
use App\Exceptions\ApiException;
use App\Http\Resources\Fmt;
use App\Models\CheckoutQuote;
use App\Models\Event;
use App\Models\InventoryHold;
use App\Models\PromoCode;
use App\Models\PromoterEnrollment;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Events\EventPresenter;
use App\Services\Platform\SettingsService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * POST /checkout/quotes — the only place prices are computed
 * (business-rules.md, "Ticket inventory and checkout"):
 *  1. event live, buyer allowed (not organiser, approved if required, 18+ if adult-only)
 *  2. each line: active, in sales window, within per-order limit and remaining stock
 *  3. subtotal = Σ price × qty + Σ food price × qty
 *  4. promo (percent or fixed, capped at the eligible subtotal)
 *  5. service fee = round(subtotal × % / 100) + fixed, when subtotal > 0 and fees pass to buyer
 *  6. total = subtotal − discount + fee, never negative
 *  7. hold inventory until expiresAt
 */
class CheckoutService
{
    public function __construct(
        private readonly Inventory $inventory,
        private readonly SettingsService $settings,
    ) {}

    /** @param array{eventId:string, lines:list<array{ticketTypeId:string, quantity:int}>, foodLines?:list<array{menuItemId:string, quantity:int}>, promoCode?:?string, referralCode?:?string} $data */
    public function quote(User $user, array $data): CheckoutQuote
    {
        $event = Event::query()->with(['plannerMenu', 'promotion'])->find($data['eventId']);
        if ($event === null || $event->status !== EventStatus::Live) {
            throw ApiException::ticketsUnavailable('This event is not selling tickets.');
        }
        $this->assertBuyerAllowed($event, $user);

        $lines = $this->mergeLines($data['lines'] ?? [], 'ticketTypeId');
        if ($lines === []) {
            throw ApiException::validation('Select at least one ticket.', ['lines' => ['Select at least one ticket.']]);
        }

        return DB::transaction(function () use ($user, $data, $event, $lines) {
            // Serialise concurrent quotes per ticket type.
            $types = TicketType::query()->where('event_id', $event->id)->whereIn('id', array_keys($lines))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $held = $this->inventory->activeHolds(array_keys($lines));

            $quoteLines = [];
            $ticketSubtotal = 0;
            $ticketCount = 0;
            $errors = [];
            $index = 0;
            foreach ($lines as $typeId => $quantity) {
                $type = $types->get($typeId);
                $field = 'lines.'.$index++.'.quantity';
                if ($type === null) {
                    throw ApiException::validation('Unknown ticket type.', [str_replace('quantity', 'ticketTypeId', $field) => ['This ticket type does not belong to the event.']]);
                }
                $now = now();
                if (! $type->is_active || ($type->sales_start_at && $type->sales_start_at->gt($now)) || ($type->sales_end_at && $type->sales_end_at->lt($now))) {
                    throw ApiException::ticketsUnavailable('Sales for '.$type->name.' are closed.', [$field => ['Sales are closed for this ticket.']]);
                }
                if ($quantity > $type->per_order_limit) {
                    throw ApiException::ticketsUnavailable('You can buy up to '.$type->per_order_limit.' '.$type->name.' tickets per order.', [$field => ['The maximum per order is '.$type->per_order_limit.'.']]);
                }
                $remaining = $this->inventory->remaining($type, $held[$typeId] ?? 0);
                if ($remaining < $quantity) {
                    $message = $remaining === 0 ? $type->name.' tickets are sold out.' : 'Only '.$remaining.' '.$type->name.' ticket'.($remaining === 1 ? ' is' : 's are').' left.';
                    $errors[$field] = [$remaining === 0 ? 'Sold out.' : 'Only '.$remaining.' left.'];
                    throw ApiException::ticketsUnavailable($message, $errors);
                }

                $subtotal = $type->price * $quantity;
                $ticketSubtotal += $subtotal;
                $ticketCount += $quantity;
                $quoteLines[] = [
                    'ticketType' => ['id' => $type->id, 'name' => $type->name, 'price' => $type->price, 'currency' => $type->currency],
                    'quantity' => $quantity,
                    'subtotal' => $subtotal,
                    'seatSelection' => $type->seat_selection,
                    'menuSelection' => $type->menu_selection,
                    'scheduleId' => $type->schedule_id,
                ];
            }

            if ($event->guest_limit !== null && $this->inventory->admittedCount($event->id) + $ticketCount > $event->guest_limit) {
                throw ApiException::ticketsUnavailable('This event has reached its guest limit.', ['lines' => ['Not enough places left for this order.']]);
            }

            [$foodLines, $foodSubtotal] = $this->priceFood($event, $data['foodLines'] ?? []);
            $subtotal = $ticketSubtotal + $foodSubtotal;

            [$promo, $discount, $promoMessage] = $this->applyPromo($event, $data['promoCode'] ?? null, $quoteLines, $subtotal);
            $serviceFee = $this->serviceFee($subtotal);
            $total = max(0, $subtotal - $discount + $serviceFee);
            $referral = $this->referral($event, $user, $data['referralCode'] ?? null);

            $expiresAt = now()->addMinutes((int) config('wedpluz.checkout.hold_minutes', 10));
            $quote = CheckoutQuote::query()->create([
                'user_id' => $user->id,
                'event_id' => $event->id,
                'lines' => $quoteLines,
                'food_lines' => $foodLines,
                'promo_code_id' => $promo?->id,
                'promo_code' => $promo?->code,
                'referral_enrollment_id' => $referral?->id,
                'subtotal' => $subtotal,
                'ticket_subtotal' => $ticketSubtotal,
                'service_fee' => $serviceFee,
                'discount' => $discount,
                'total' => $total,
                'currency' => $event->currency,
                'requires_seat_selection' => collect($quoteLines)->contains('seatSelection', true),
                'requires_menu_selection' => collect($quoteLines)->contains('menuSelection', true),
                'expires_at' => $expiresAt,
            ]);
            $quote->setAttribute('promo_message', $promoMessage);

            foreach ($lines as $typeId => $quantity) {
                InventoryHold::query()->create(['quote_id' => $quote->id, 'ticket_type_id' => $typeId, 'quantity' => $quantity, 'expires_at' => $expiresAt]);
            }

            return $quote;
        });
    }

    public function release(User $user, string $quoteId): void
    {
        $quote = CheckoutQuote::query()->where('user_id', $user->id)->find($quoteId);
        if ($quote === null) {
            throw ApiException::notFound('Quote not found.');
        }
        if ($quote->consumed_at !== null) {
            return; // converted into an order; the order owns the hold now
        }
        DB::transaction(function () use ($quote) {
            $quote->forceFill(['released_at' => now()])->save();
            InventoryHold::query()->where('quote_id', $quote->id)->whereNull('order_id')->whereNull('released_at')->update(['released_at' => now()]);
        });
    }

    private function assertBuyerAllowed(Event $event, User $user): void
    {
        if ($event->organizer_user_id === $user->id) {
            throw ApiException::forbidden('Organisers cannot buy tickets to their own event.');
        }
        if ($event->is_adult_only) {
            if ($user->date_of_birth === null) {
                throw ApiException::identityUnverified('Add your date of birth to buy tickets for 18+ events.');
            }
            if (! $user->isAdult()) {
                throw ApiException::forbidden('This event is for attendees aged 18 and over.');
            }
        }
        if ($event->approval_required) {
            $approved = DB::table('event_requests')->where('event_id', $event->id)->where('user_id', $user->id)->where('status', 'approved')->exists();
            if (! $approved) {
                throw ApiException::forbidden('This event requires the organiser\'s approval. Request to attend first.');
            }
        }
    }

    /** @return array<string,int> id => quantity (duplicates merged, zero quantities dropped) */
    private function mergeLines(array $lines, string $key): array
    {
        $merged = [];
        foreach ($lines as $line) {
            $qty = (int) ($line['quantity'] ?? 0);
            if ($qty > 0) {
                $merged[$line[$key]] = ($merged[$line[$key]] ?? 0) + $qty;
            }
        }

        return $merged;
    }

    /** @return array{0:list<array>, 1:int} */
    private function priceFood(Event $event, array $foodLines): array
    {
        $requested = $this->mergeLines($foodLines, 'menuItemId');
        if ($requested === []) {
            return [[], 0];
        }
        $menu = collect(EventPresenter::menu($event->plannerMenu, $event->currency))->keyBy('id');
        $lines = [];
        $total = 0;
        $i = 0;
        foreach ($requested as $itemId => $qty) {
            $item = $menu->get($itemId);
            if ($item === null || ! $item['isAvailable']) {
                throw ApiException::validation('A selected dish is not available.', ['foodLines.'.$i.'.menuItemId' => ['This dish is not available.']]);
            }
            if ($qty > 50) {
                throw ApiException::validation('Too many of one dish.', ['foodLines.'.$i.'.quantity' => ['The maximum is 50.']]);
            }
            $subtotal = $item['price'] * $qty;
            $total += $subtotal;
            $lines[] = ['menuItem' => ['id' => $item['id'], 'name' => $item['name'], 'price' => $item['price']], 'quantity' => $qty, 'subtotal' => $subtotal];
            $i++;
        }

        return [$lines, $total];
    }

    /** @return array{0:?PromoCode, 1:int, 2:?string} */
    private function applyPromo(Event $event, ?string $code, array $lines, int $subtotal): array
    {
        $code = $code !== null ? strtoupper(trim($code)) : '';
        if ($code === '') {
            return [null, 0, null];
        }

        $promo = PromoCode::query()->whereRaw('upper(code) = ?', [$code])
            ->where(fn ($q) => $q->where('event_id', $event->id)->orWhereNull('event_id'))
            ->orderByRaw('event_id is null')->first();
        $now = now();
        if ($promo === null || ! $promo->is_active
            || ($promo->starts_at && $promo->starts_at->gt($now))
            || ($promo->ends_at && $promo->ends_at->lt($now))) {
            throw ApiException::promoInvalid('This promo code is not valid.');
        }
        if ($promo->max_uses !== null && $promo->used_count >= $promo->max_uses) {
            throw ApiException::promoInvalid('This promo code has been fully used.');
        }

        $scope = $promo->applies_to_ticket_type_ids ?: null;
        $eligible = $scope === null
            ? $subtotal
            : (int) collect($lines)->filter(fn ($l) => in_array($l['ticketType']['id'], $scope, true))->sum('subtotal');
        if ($eligible <= 0) {
            throw ApiException::promoInvalid('This promo code does not apply to your tickets.');
        }

        $discount = $promo->type === 'percent'
            ? (int) round($eligible * min(100, max(0, $promo->value)) / 100)
            : (int) $promo->value;
        $discount = min($discount, $eligible);

        return [$promo, $discount, 'Promo applied: '.Money::format($discount, $event->currency).' off.'];
    }

    public function serviceFee(int $subtotal): int
    {
        if ($subtotal <= 0 || ! $this->settings->get('payments.passFeesToBuyer', true)) {
            return 0;
        }

        return (int) round($subtotal * (float) $this->settings->get('payments.serviceFeePercent', 3) / 100)
            + (int) $this->settings->get('payments.serviceFeeFixed', 0);
    }

    /** Invalid referral codes are ignored silently, never an error. */
    private function referral(Event $event, User $buyer, ?string $code): ?PromoterEnrollment
    {
        $code = trim((string) $code);
        if ($code === '' || ! $event->promotion?->enabled) {
            return null;
        }
        $enrollment = PromoterEnrollment::query()->where('share_code', strtoupper($code))->where('event_id', $event->id)->where('status', 'active')->first();

        return $enrollment && $enrollment->user_id !== $buyer->id ? $enrollment : null;
    }

    /** CheckoutQuote resource. */
    public static function resource(CheckoutQuote $q): array
    {
        return [
            'quoteId' => $q->id,
            'currency' => $q->currency,
            'lines' => array_map(fn ($l) => ['ticketType' => $l['ticketType'], 'quantity' => $l['quantity'], 'subtotal' => $l['subtotal']], $q->lines),
            'foodLines' => $q->food_lines,
            'subtotal' => $q->subtotal,
            'serviceFee' => $q->service_fee,
            'discount' => $q->discount,
            'total' => $q->total,
            'promoCode' => $q->promo_code,
            'promoMessage' => $q->getAttribute('promo_message'),
            'requiresSeatSelection' => $q->requires_seat_selection,
            'requiresMenuSelection' => $q->requires_menu_selection,
            'expiresAt' => Fmt::date($q->expires_at),
        ];
    }
}
