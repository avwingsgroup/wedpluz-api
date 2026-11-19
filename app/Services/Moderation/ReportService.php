<?php

namespace App\Services\Moderation;

use App\Exceptions\ApiException;
use App\Http\Resources\ContentReportResource;
use App\Models\ContentReport;
use App\Models\User;
use App\Services\Realtime\Realtime;
use Illuminate\Support\Facades\DB;

/**
 * POST /reports. One report per reporter and target (a duplicate updates the
 * existing report). Priority: high for violence, hate speech or nudity, or 3+
 * reports on the target in 24 hours; medium for scam/fraud and harassment;
 * low otherwise. `blockUser` also blocks the reported user for the reporter.
 */
class ReportService
{
    private const HIGH = ['violence', 'hate_speech', 'nudity'];

    private const MEDIUM = ['scam_or_fraud', 'harassment'];

    public function __construct(private readonly ModerationTargets $targets) {}

    /** @param array{targetType:string, targetId:string, reason:string, details?:?string, blockUser?:bool} $data */
    public function report(User $reporter, array $data): ContentReport
    {
        $target = $this->targets->find($data['targetType'], $data['targetId']);
        if ($target === null) {
            throw ApiException::notFound('The content you are reporting was not found.');
        }
        $owner = $this->targets->owner($data['targetType'], $target);
        if ($owner?->id === $reporter->id) {
            throw ApiException::validation('You cannot report your own content.', ['targetId' => ['You cannot report your own content.']]);
        }

        $report = DB::transaction(function () use ($reporter, $data, $target, $owner) {
            $recent = ContentReport::query()->where('target_type', $data['targetType'])->where('target_id', $data['targetId'])
                ->where('created_at', '>=', now()->subDay())->count();
            $priority = match (true) {
                in_array($data['reason'], self::HIGH, true), $recent + 1 >= 3 => 'high',
                in_array($data['reason'], self::MEDIUM, true) => 'medium',
                default => 'low',
            };

            $report = ContentReport::query()->updateOrCreate(
                ['reporter_user_id' => $reporter->id, 'target_type' => $data['targetType'], 'target_id' => $data['targetId']],
                [
                    'reported_user_id' => $owner?->id,
                    'reason' => $data['reason'],
                    'details' => $data['details'] ?? null,
                    'priority' => $priority,
                    'target_preview' => $this->targets->preview($data['targetType'], $target),
                ],
            );
            if ($report->wasRecentlyCreated === false && in_array($report->status, ['dismissed', 'actioned'], true)) {
                $report->forceFill(['status' => 'open', 'resolution' => null])->save();
            }

            if ($priority === 'high') {
                ContentReport::query()->where('target_type', $data['targetType'])->where('target_id', $data['targetId'])
                    ->whereIn('status', ['open', 'reviewing'])->update(['priority' => 'high']);
            }

            if (! empty($data['blockUser']) && $owner && $owner->id !== $reporter->id) {
                DB::table('blocks')->insertOrIgnore(['blocker_user_id' => $reporter->id, 'blocked_user_id' => $owner->id, 'created_at' => now()]);
            }

            return $report;
        });

        if ($report->wasRecentlyCreated) {
            Realtime::emit('admin.moderation', 'admin.report.created', (new ContentReportResource($report->load(['reporter', 'reportedUser', 'assignee'])))->resolve());
        }

        return $report;
    }
}
