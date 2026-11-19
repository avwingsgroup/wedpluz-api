<?php

namespace App\Services\Events;

use App\Enums\TaskStatus;
use App\Exceptions\ApiException;
use App\Http\Resources\Fmt;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\EventTaskAttachment;
use App\Models\User;
use App\Services\Media\UploadService;
use App\Services\Notifications\NotificationService;
use App\Services\Realtime\Realtime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Event tasks. `manage_tasks` holders have full CRUD; vendors may only see and
 * update the status of tasks assigned to them. Assignees must be the
 * organiser, accepted managers or accepted vendors of the event.
 */
class TaskService
{
    public function __construct(
        private readonly EventAccess $access,
        private readonly ActivityLogger $activity,
        private readonly NotificationService $notifications,
        private readonly UploadService $uploads,
    ) {}

    /** @return Collection<int, EventTask> */
    public function list(Event $event, User $user, ?string $assigneeId, ?string $search, string $sort): Collection
    {
        $canManage = $this->access->can($user, $event, 'manage_tasks');

        return EventTask::query()->with(['assignees', 'attachments', 'createdBy'])->where('event_id', $event->id)
            ->when(! $canManage, fn ($q) => $q->whereHas('assignees', fn ($a) => $a->where('users.id', $user->id)))
            ->when($assigneeId, fn ($q, $id) => $q->whereHas('assignees', fn ($a) => $a->where('users.id', $id)))
            ->when($search && mb_strlen($search) >= 2, fn ($q) => $q->where('title', 'ilike', '%'.addcslashes((string) $search, '%_\\').'%'))
            ->when($sort === 'due', fn ($q) => $q->orderByRaw('due_at asc nulls last'), fn ($q) => $q->orderByDesc('created_at'))
            ->get();
    }

    /** @param array<string,mixed> $data */
    public function create(Event $event, User $actor, array $data): EventTask
    {
        $this->access->authorize($actor, $event, 'manage_tasks');

        $task = DB::transaction(function () use ($event, $actor, $data) {
            $task = EventTask::query()->create([
                'event_id' => $event->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? TaskStatus::Todo->value,
                'due_at' => $data['dueAt'] ?? null,
                'created_by_user_id' => $actor->id,
                'completed_at' => ($data['status'] ?? null) === 'done' ? now() : null,
            ]);
            $this->syncAssignees($event, $task, $data['assigneeIds'] ?? [], $actor);
            $this->attach($task, $actor, $data['attachmentUploadIds'] ?? []);
            $this->activity->log($event, $actor, 'task.created', 'Created task "'.$task->title.'"');

            return $task;
        });

        return $this->broadcast($task);
    }

    /** @param array<string,mixed> $data */
    public function update(Event $event, User $actor, EventTask $task, array $data): EventTask
    {
        $canManage = $this->access->can($actor, $event, 'manage_tasks');
        if (! $canManage) {
            $isAssignee = $task->assignees()->where('users.id', $actor->id)->exists();
            if (! $isAssignee || array_diff(array_keys($data), ['status']) !== []) {
                throw ApiException::forbidden('You can only update the status of tasks assigned to you.');
            }
        }

        DB::transaction(function () use ($event, $actor, $task, $data) {
            $changes = [];
            foreach (['title' => 'title', 'description' => 'description', 'dueAt' => 'due_at'] as $key => $column) {
                if (array_key_exists($key, $data)) {
                    $changes[$column] = $data[$key];
                }
            }
            if (array_key_exists('status', $data)) {
                $changes['status'] = $data['status'];
                $changes['completed_at'] = $data['status'] === 'done' ? ($task->completed_at ?? now()) : null;
            }
            $task->forceFill($changes)->save();
            if (array_key_exists('assigneeIds', $data)) {
                $this->syncAssignees($event, $task, $data['assigneeIds'] ?? [], $actor);
            }
            if (! empty($data['attachmentUploadIds'])) {
                $this->attach($task, $actor, $data['attachmentUploadIds']);
            }
            $this->activity->log($event, $actor, 'task.updated', 'Updated task "'.$task->title.'"', ['fields' => array_keys($data)]);
        });

        return $this->broadcast($task->refresh());
    }

    public function delete(Event $event, User $actor, EventTask $task): void
    {
        $this->access->authorize($actor, $event, 'manage_tasks');
        $task->delete();
        $this->activity->log($event, $actor, 'task.deleted', 'Deleted task "'.$task->title.'"');
    }

    /** @param list<string> $userIds */
    private function syncAssignees(Event $event, EventTask $task, array $userIds, User $actor): void
    {
        $userIds = array_values(array_unique($userIds));
        $allowed = collect([$event->organizer_user_id])
            ->merge(DB::table('event_managers')->where('event_id', $event->id)->where('status', 'accepted')->pluck('user_id'))
            ->merge(DB::table('event_vendor_assignments')->join('vendor_profiles', 'vendor_profiles.id', '=', 'event_vendor_assignments.vendor_profile_id')
                ->where('event_vendor_assignments.event_id', $event->id)->where('event_vendor_assignments.status', 'accepted')->pluck('vendor_profiles.user_id'))
            ->unique()->all();
        $invalid = array_diff($userIds, $allowed);
        if ($invalid !== []) {
            throw ApiException::validation('Tasks can only be assigned to the event team.', ['assigneeIds' => ['Assign tasks to the organiser, managers or vendors of this event.']]);
        }

        $before = $task->assignees()->pluck('users.id')->all();
        $task->assignees()->sync($userIds);
        foreach (array_diff($userIds, $before, [$actor->id]) as $newId) {
            $assignee = User::query()->find($newId);
            if ($assignee) {
                $this->notifications->notify($assignee, 'task_assigned', [
                    'actor' => $actor,
                    'tokens' => ['task' => $task->title, 'event' => $event->title],
                    'data' => ['eventId' => $event->id, 'taskId' => $task->id],
                ]);
            }
        }
    }

    /** @param list<string> $uploadIds */
    private function attach(EventTask $task, User $actor, array $uploadIds): void
    {
        foreach ($uploadIds as $i => $uploadId) {
            $upload = $this->uploads->consume($uploadId, $actor, ['task_attachment', 'document'], "attachmentUploadIds.$i");
            EventTaskAttachment::query()->create([
                'task_id' => $task->id,
                'upload_id' => $upload->id,
                'name' => $upload->original_name ?: 'Attachment',
                'url' => (string) ($upload->url ?? $this->uploads->temporaryUrl($upload)),
            ]);
        }
    }

    private function broadcast(EventTask $task): EventTask
    {
        $task->load(['assignees', 'attachments', 'createdBy']);
        $payload = self::resource($task);
        Realtime::emit('event.'.$task->event_id.'.manage', 'task.updated', $payload);
        foreach ($task->assignees as $assignee) {
            Realtime::toUser($assignee->id, 'task.updated', $payload);
        }

        return $task;
    }

    public static function resource(EventTask $t): array
    {
        return [
            'id' => $t->id,
            'eventId' => $t->event_id,
            'title' => $t->title,
            'description' => $t->description,
            'status' => $t->status->value,
            'dueAt' => Fmt::date($t->due_at),
            'assignees' => $t->assignees->map(fn ($u) => Fmt::user($u))->values()->all(),
            'attachments' => $t->attachments->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'url' => $a->url])->values()->all(),
            'createdBy' => Fmt::user($t->createdBy),
            'createdAt' => Fmt::date($t->created_at),
            'completedAt' => Fmt::date($t->completed_at),
            'isOverdue' => $t->isOverdue(),
        ];
    }
}
