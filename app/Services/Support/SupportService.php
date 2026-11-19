<?php

namespace App\Services\Support;

use App\Http\Middleware\RequestContext;
use App\Http\Resources\Fmt;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\Codes;
use Illuminate\Support\Facades\DB;

class SupportService
{
    public const CATEGORIES = ['payment', 'ticket', 'account', 'event', 'vendor', 'bug', 'other'];

    public function open(User $user, string $subject, string $category, string $message): SupportTicket
    {
        return DB::transaction(function () use ($user, $subject, $category, $message) {
            $ticket = SupportTicket::query()->create([
                'reference' => Codes::reference('SUP', 'support_tickets'),
                'user_id' => $user->id,
                'subject' => $subject,
                'category' => in_array($category, self::CATEGORIES, true) ? $category : 'other',
                'status' => 'open',
                'priority' => $category === 'payment' ? 'high' : 'medium',
                'source' => RequestContext::source(),
            ]);
            SupportMessage::query()->create(['ticket_id' => $ticket->id, 'author_user_id' => $user->id, 'body' => $message]);

            return $ticket->setRelation('user', $user);
        });
    }

    public static function resource(SupportTicket $t, ?int $messagesCount = null): array
    {
        return [
            'id' => $t->id,
            'reference' => $t->reference,
            'user' => Fmt::userWithEmail($t->user),
            'subject' => $t->subject,
            'category' => $t->category,
            'status' => $t->status,
            'priority' => $t->priority,
            'assignedTo' => Fmt::admin($t->assignee),
            'source' => $t->source,
            'messagesCount' => $messagesCount ?? (int) ($t->messages_count ?? SupportMessage::query()->where('ticket_id', $t->id)->count()),
            'createdAt' => Fmt::date($t->created_at),
            'updatedAt' => Fmt::date($t->updated_at),
        ];
    }
}
