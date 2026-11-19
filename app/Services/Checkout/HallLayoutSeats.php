<?php

namespace App\Services\Checkout;

use App\Http\Resources\Fmt;
use App\Models\HallLayout;
use Illuminate\Support\Facades\DB;

/** Seat lookups and the seat-map view of a hall layout for a schedule. */
final class HallLayoutSeats
{
    /** @return array{id:string, label:string, status:string, ticketTypeId:?string, tableLabel:string}|null */
    public static function find(HallLayout $layout, string $seatId): ?array
    {
        foreach ($layout->tables ?? [] as $table) {
            foreach ($table['seats'] ?? [] as $seat) {
                if ((string) ($seat['id'] ?? '') === $seatId) {
                    return [
                        'id' => (string) $seat['id'],
                        'label' => (string) ($seat['label'] ?? $seat['id']),
                        'status' => (string) ($seat['status'] ?? 'available'),
                        'ticketTypeId' => $seat['ticketTypeId'] ?? null,
                        'tableLabel' => (string) ($table['label'] ?? $table['id'] ?? ''),
                    ];
                }
            }
        }

        return null;
    }

    /** HallLayout resource with live seat availability for one schedule. */
    public static function forSchedule(HallLayout $layout, ?string $scheduleId): array
    {
        $taken = $scheduleId
            ? DB::table('seat_reservations')->where('schedule_id', $scheduleId)->whereIn('status', ['held', 'confirmed'])->pluck('seat_id')->flip()->all()
            : [];

        $tables = array_map(function (array $table) use ($taken) {
            $table['seats'] = array_map(function (array $seat) use ($taken) {
                $status = ($seat['status'] ?? 'available') === 'reserved' ? 'reserved' : (isset($taken[(string) $seat['id']]) ? 'taken' : 'available');

                return [
                    'id' => (string) $seat['id'],
                    'label' => (string) ($seat['label'] ?? $seat['id']),
                    'status' => $status,
                    'ticketTypeId' => $seat['ticketTypeId'] ?? null,
                ];
            }, $table['seats'] ?? []);

            return $table;
        }, $layout->tables ?? []);

        return self::resource($layout, $tables);
    }

    public static function resource(HallLayout $layout, ?array $tables = null): array
    {
        return [
            'id' => $layout->id,
            'name' => $layout->name,
            'thumbnailUrl' => $layout->thumbnail_url,
            'width' => $layout->width,
            'height' => $layout->height,
            'totalSeats' => $layout->total_seats,
            'reservedSeats' => $layout->reserved_seats,
            'tables' => array_values($tables ?? $layout->tables ?? []),
            'fixtures' => array_values($layout->fixtures ?? []),
            'updatedAt' => Fmt::date($layout->updated_at),
        ];
    }
}
