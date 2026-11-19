<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Fmt;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\Admin\AuditLogger;
use App\Services\Notifications\NotificationService;
use App\Services\Support\SupportService;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSupportController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $list = ListQuery::from($request);
        $assigned = $list->filter('assigned');
        $query = SupportTicket::query()->with(['user', 'assignee'])->withCount('messages')
            ->when($list->filter('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($list->filter('priority'), fn ($q, $p) => $q->where('priority', $p))
            ->when($list->filter('category'), fn ($q, $c) => $q->where('category', $c))
            ->when($assigned === 'me', fn ($q) => $q->where('assigned_admin_id', $this->admin()->id))
            ->when($assigned === 'unassigned', fn ($q) => $q->whereNull('assigned_admin_id'))
            ->when($list->searchPattern(), fn ($q, $p) => $q->where(fn ($w) => $w->where('reference', 'ilike', $p)->orWhere('subject', 'ilike', $p)
                ->orWhereHas('user', fn ($u) => $u->where('email', 'ilike', $p)->orWhere('display_name', 'ilike', $p))));
        $list->applySort($query, ['createdAt' => 'created_at', 'updatedAt' => 'updated_at', 'priority' => "case priority when 'urgent' then 4 when 'high' then 3 when 'medium' then 2 else 1 end"], 'updatedAt');

        return $this->paginated($list->paginate($query), fn (SupportTicket $t) => SupportService::resource($t, $t->messages_count));
    }

    public function show(string $id): JsonResponse
    {
        $ticket = SupportTicket::query()->with(['user', 'assignee'])->findOrFail($id);
        $messages = SupportMessage::query()->with(['authorUser', 'authorAdmin'])->where('ticket_id', $ticket->id)->orderBy('created_at')->get();

        return $this->ok(SupportService::resource($ticket, $messages->count()) + [
            'messages' => $messages->map(fn (SupportMessage $m) => [
                'id' => $m->id,
                'author' => $m->author_admin_id
                    ? ['id' => $m->author_admin_id, 'displayName' => $m->authorAdmin?->fullName() ?? 'Wedpluz Support', 'avatarUrl' => $m->authorAdmin?->avatar_url]
                    : Fmt::user($m->authorUser),
                'isStaff' => $m->author_admin_id !== null,
                'body' => $m->body,
                'createdAt' => Fmt::date($m->created_at),
            ])->all(),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', 'in:open,pending,resolved,closed'], 'priority' => ['sometimes', 'in:low,medium,high,urgent'], 'assignToMe' => ['sometimes', 'boolean']]);
        $ticket = SupportTicket::query()->with(['user', 'assignee'])->findOrFail($id);
        $before = $ticket->only(['status', 'priority', 'assigned_admin_id']);
        $ticket->forceFill(array_filter([
            'status' => $data['status'] ?? null,
            'priority' => $data['priority'] ?? null,
            'assigned_admin_id' => ! empty($data['assignToMe']) ? $this->admin()->id : null,
        ], fn ($v) => $v !== null))->save();
        $this->audit->log('support.updated', 'support', $ticket, $ticket->reference, $before, $data);

        return $this->ok(SupportService::resource($ticket->refresh()->load(['user', 'assignee'])));
    }

    public function reply(Request $request, string $id, NotificationService $notifications): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $ticket = SupportTicket::query()->with('user')->findOrFail($id);
        $message = SupportMessage::query()->create(['ticket_id' => $ticket->id, 'author_admin_id' => $this->admin()->id, 'body' => $data['body']]);
        $ticket->forceFill(['status' => $ticket->status === 'open' ? 'pending' : $ticket->status, 'assigned_admin_id' => $ticket->assigned_admin_id ?? $this->admin()->id])->save();
        $ticket->touch();
        if ($ticket->user) {
            $notifications->sendDirect('email', $ticket->user->email, 'support_reply', [
                'firstName' => $ticket->user->first_name, 'reference' => $ticket->reference, 'body' => $data['body'],
            ], 'support-reply:'.$message->id, $ticket->user);
        }
        $this->audit->log('support.replied', 'support', $ticket, $ticket->reference);

        return $this->ok(['id' => $message->id, 'body' => $message->body]);
    }
}
