<?php

namespace App\Services\Platform;

use App\Http\Resources\Fmt;
use App\Models\SystemAlert;
use App\Services\Realtime\Realtime;
use App\Support\Sql;
use Illuminate\Support\Facades\Log;

/** System alerts surfaced on the admin dashboard (`alerts`) and the `private-admin` channel. */
class AlertService
{
    public function raise(string $severity, string $title, string $description, ?string $link = null, ?string $dedupeKey = null): ?SystemAlert
    {
        Log::log($severity === 'critical' ? 'error' : 'warning', 'system_alert', ['title' => $title, 'description' => $description]);

        $alert = Sql::insertOrNull(fn () => SystemAlert::query()->create([
            'severity' => $severity,
            'title' => mb_substr($title, 0, 200),
            'description' => mb_substr($description, 0, 1000),
            'link' => $link,
            'dedupe_key' => $dedupeKey,
        ]));

        if ($alert) {
            Realtime::emit('admin', 'admin.alert', self::resource($alert));
        }

        return $alert;
    }

    public static function resource(SystemAlert $alert): array
    {
        return [
            'id' => $alert->id,
            'severity' => $alert->severity,
            'title' => $alert->title,
            'description' => $alert->description,
            'link' => $alert->link,
            'createdAt' => Fmt::date($alert->created_at),
        ];
    }
}
