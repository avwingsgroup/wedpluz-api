<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContentReportResource;
use App\Models\Community;
use App\Models\ContentReport;
use App\Services\Admin\AdminPresenter;
use App\Services\Admin\AuditLogger;
use App\Services\Moderation\ModerationService;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminModerationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function reports(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);
        $assigned = $list->filter('assigned');
        $query = ContentReport::query()->with(['reporter', 'reportedUser', 'assignee'])
            ->when($list->filter('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($list->filter('priority'), fn ($q, $p) => $q->where('priority', $p))
            ->when($list->filter('targetType'), fn ($q, $t) => $q->where('target_type', $t))
            ->when($assigned === 'me', fn ($q) => $q->where('assigned_admin_id', $this->admin()->id))
            ->when($assigned === 'unassigned', fn ($q) => $q->whereNull('assigned_admin_id'))
            ->when($list->searchPattern(), fn ($q, $p) => $q->where(fn ($w) => $w->where('details', 'ilike', $p)->orWhereRaw("target_preview->>'title' ilike ?", [$p])));
        $list->applySort($query, ['createdAt' => 'created_at', 'priority' => "case priority when 'high' then 3 when 'medium' then 2 else 1 end"], 'createdAt');

        return $this->paginated($list->paginate($query), ContentReportResource::class);
    }

    public function show(string $id): JsonResponse
    {
        $report = ContentReport::query()->with(['reporter', 'reportedUser', 'assignee'])->findOrFail($id);
        $history = ContentReport::query()->with(['reporter', 'reportedUser', 'assignee'])
            ->where('id', '!=', $report->id)
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('target_type', $report->target_type)->where('target_id', $report->target_id))
                ->when($report->reported_user_id, fn ($w) => $w->orWhere('reported_user_id', $report->reported_user_id)))
            ->latest()->limit(50)->get();

        return $this->ok((new ContentReportResource($report))->resolve() + ['history' => ContentReportResource::collection($history)->resolve()]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['assignToMe' => ['sometimes', 'boolean'], 'priority' => ['sometimes', 'in:low,medium,high'], 'status' => ['sometimes', 'in:reviewing']]);
        $report = ContentReport::query()->findOrFail($id);
        $before = $report->only(['priority', 'status', 'assigned_admin_id']);
        $report->forceFill(array_filter([
            'assigned_admin_id' => ! empty($data['assignToMe']) ? $this->admin()->id : null,
            'priority' => $data['priority'] ?? null,
            'status' => $data['status'] ?? null,
        ], fn ($v) => $v !== null))->save();
        $this->audit->log('report.updated', 'moderation', $report, $report->target_type.':'.$report->target_id, $before, $data);

        return $this->ok(new ContentReportResource($report->load(['reporter', 'reportedUser', 'assignee'])));
    }

    public function act(Request $request, string $id, ModerationService $moderation): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:removed,restored,warned,suspended,dismissed'], 'note' => ['required', 'string', 'min:3', 'max:2000']]);
        $report = ContentReport::query()->with(['reporter', 'reportedUser', 'assignee'])->findOrFail($id);
        if (in_array($report->status, ['actioned', 'dismissed'], true) && $data['action'] !== 'restored') {
            throw ApiException::conflict('This report is already resolved.');
        }
        $moderation->act($report, $this->admin(), $data['action'], $data['note']);
        $this->audit->log('report.'.$data['action'], 'moderation', $report, $report->target_type.':'.$report->target_id, null, $data);

        return $this->ok(new ContentReportResource($report->refresh()->load(['reporter', 'reportedUser', 'assignee'])));
    }

    public function communities(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);
        $query = Community::query()->with(['event', 'owner'])
            ->when($list->filter('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($list->searchPattern(), fn ($q, $p) => $q->where('name', 'ilike', $p));
        $list->applySort($query, ['createdAt' => 'created_at', 'name' => 'name'], 'createdAt');
        $page = $list->paginate($query);
        $ids = collect($page->items())->pluck('id');
        $members = DB::table('community_members')->whereIn('community_id', $ids)->selectRaw('community_id, count(*) c')->groupBy('community_id')->pluck('c', 'community_id');
        $messages = DB::table('messages')->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->whereIn('conversations.community_id', $ids)->where('messages.created_at', '>=', now()->subDays(7))
            ->selectRaw('conversations.community_id, count(*) c')->groupBy('conversations.community_id')->pluck('c', 'community_id');
        $reports = DB::table('content_reports')->where('target_type', 'community')->whereIn('target_id', $ids)->whereIn('status', ['open', 'reviewing'])
            ->selectRaw('target_id, count(*) c')->groupBy('target_id')->pluck('c', 'target_id');

        return $this->paginated($page, fn (Community $c) => AdminPresenter::community($c, [
            'members' => $members[$c->id] ?? 0, 'messages' => $messages[$c->id] ?? 0, 'reports' => $reports[$c->id] ?? 0,
        ]));
    }

    public function suspendCommunity(string $id): JsonResponse
    {
        return $this->setCommunityStatus($id, 'suspended');
    }

    public function restoreCommunity(string $id): JsonResponse
    {
        return $this->setCommunityStatus($id, 'active');
    }

    public function archiveCommunity(string $id): JsonResponse
    {
        return $this->setCommunityStatus($id, 'archived');
    }

    private function setCommunityStatus(string $id, string $status): JsonResponse
    {
        $community = Community::query()->with(['event', 'owner'])->findOrFail($id);
        $before = $community->status->value;
        $community->forceFill(['status' => $status])->save();
        $this->audit->log('community.'.$status, 'moderation', $community, $community->name, ['status' => $before], ['status' => $status]);

        return $this->ok(AdminPresenter::community($community, ['members' => DB::table('community_members')->where('community_id', $community->id)->count()]));
    }
}
