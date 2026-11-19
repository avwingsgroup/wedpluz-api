<?php

namespace App\Services\Moderation;

use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\AdminUser;
use App\Models\Community;
use App\Models\ContentReport;
use App\Models\Event;
use App\Models\Message;
use App\Models\ModerationRemoval;
use App\Models\StatusPost;
use App\Models\User;
use App\Models\VendorProfile;
use App\Models\VendorReview;
use App\Models\VendorWork;
use App\Services\Auth\TokenService;
use App\Services\Notifications\NotificationService;
use App\Services\Realtime\Realtime;
use App\Services\Vendors\VendorService;
use Illuminate\Support\Facades\DB;

/**
 * Moderation actions (business-rules.md, "Moderation"):
 *   removed   hides the content (soft delete, kept 90 days for appeals)
 *   restored  reverses a removal
 *   warned    notifies the reported user
 *   suspended suspends the reported user
 *   dismissed closes with no action
 * Every action needs a note; the reporter is told the outcome without detail.
 */
class ModerationService
{
    public function __construct(
        private readonly ModerationTargets $targets,
        private readonly NotificationService $notifications,
        private readonly TokenService $tokens,
    ) {}

    public function act(ContentReport $report, AdminUser $admin, string $action, string $note): ContentReport
    {
        DB::transaction(function () use ($report, $admin, $action, $note) {
            match ($action) {
                'removed' => $this->remove($report, $admin, $note),
                'restored' => $this->restore($report),
                'suspended' => $this->suspendUser($report, $note),
                default => null,
            };

            $resolution = ['action' => $action, 'note' => $note, 'by' => $admin->fullName(), 'at' => now()->toIso8601String()];
            ContentReport::query()->where('target_type', $report->target_type)->where('target_id', $report->target_id)
                ->whereIn('status', ['open', 'reviewing'])
                ->update(['status' => $action === 'dismissed' ? 'dismissed' : 'actioned', 'resolution' => json_encode($resolution), 'updated_at' => now()]);
            $report->forceFill(['status' => $action === 'dismissed' ? 'dismissed' : 'actioned', 'resolution' => $resolution])->save();
        });

        if ($action === 'warned' && $report->reportedUser) {
            $this->notifications->notify($report->reportedUser, 'system', [
                'title' => 'Community guidelines warning',
                'body' => 'Some of your content was reported and reviewed by our team. Please follow the Wedpluz community guidelines to keep your account in good standing.',
            ]);
        }
        $reporter = User::query()->find($report->reporter_user_id);
        if ($reporter) {
            $this->notifications->notify($reporter, 'system', [
                'title' => 'Report reviewed',
                'body' => $action === 'dismissed' ? 'We reviewed your report and found no violation.' : 'Thanks for your report. We reviewed it and took action.',
                'dedupe' => 'report-outcome:'.$report->id,
            ]);
        }

        return $report;
    }

    private function remove(ContentReport $report, AdminUser $admin, string $note): void
    {
        $target = $this->targets->find($report->target_type, $report->target_id, true) ?? throw ApiException::notFound('The reported content no longer exists.');

        match (true) {
            $target instanceof Message => $this->removeMessage($target),
            $target instanceof Event => $target->forceFill(['status_before_suspension' => $target->status->value, 'status' => 'suspended', 'suspended_reason' => 'Removed by moderation: '.$note])->save(),
            $target instanceof Community => $target->forceFill(['status' => 'suspended'])->save(),
            $target instanceof VendorProfile => $target->forceFill(['status' => 'suspended', 'suspended_reason' => $note])->save(),
            $target instanceof User => $this->suspendUser($report, $note),
            $target instanceof VendorReview => $this->removeReview($target),
            $target instanceof StatusPost, $target instanceof VendorWork => $target->delete(),
            default => null,
        };

        ModerationRemoval::query()->create([
            'target_type' => $report->target_type, 'target_id' => $report->target_id, 'report_id' => $report->id,
            'admin_user_id' => $admin->id, 'note' => $note,
        ]);
    }

    private function restore(ContentReport $report): void
    {
        $target = $this->targets->find($report->target_type, $report->target_id, true) ?? throw ApiException::notFound('The reported content no longer exists.');
        $removal = ModerationRemoval::query()->where('target_type', $report->target_type)->where('target_id', $report->target_id)->whereNull('restored_at')->latest()->first();
        if ($removal === null) {
            throw ApiException::conflict('This content was not removed by moderation.');
        }

        match (true) {
            $target instanceof Message => $target->forceFill(['deleted_by' => 'none', 'deleted_at' => null])->save(),
            $target instanceof Event => $target->forceFill(['status' => $target->status_before_suspension ?: 'live', 'status_before_suspension' => null, 'suspended_reason' => null])->save(),
            $target instanceof Community => $target->forceFill(['status' => 'active'])->save(),
            $target instanceof VendorProfile => $target->forceFill(['status' => 'active', 'suspended_reason' => null])->save(),
            $target instanceof User => $target->forceFill(['status' => UserStatus::Active, 'suspended_reason' => null])->save(),
            $target instanceof VendorReview => $this->restoreReview($target),
            $target instanceof StatusPost, $target instanceof VendorWork => $target->restore(),
            default => null,
        };
        $removal->forceFill(['restored_at' => now()])->save();
    }

    private function suspendUser(ContentReport $report, string $note): void
    {
        $user = User::query()->find($report->reported_user_id) ?? throw ApiException::conflict('There is no user to suspend for this report.');
        $user->forceFill(['status' => UserStatus::Suspended, 'suspended_reason' => $note, 'suspended_at' => now()])->save();
        $this->tokens->revokeAllForUser($user);
    }

    private function removeMessage(Message $message): void
    {
        $message->forceFill(['deleted_by' => 'admin', 'deleted_at' => now(), 'is_pinned' => false])->save();
        Realtime::emit('conversation.'.$message->conversation_id, 'message.deleted', ['id' => $message->id, 'conversationId' => $message->conversation_id, 'deletedBy' => 'admin']);
    }

    private function removeReview(VendorReview $review): void
    {
        $review->delete();
        app(VendorService::class)->recomputeRating($review->vendor_profile_id);
    }

    private function restoreReview(VendorReview $review): void
    {
        $review->restore();
        app(VendorService::class)->recomputeRating($review->vendor_profile_id);
    }
}
