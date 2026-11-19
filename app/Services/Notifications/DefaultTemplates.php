<?php

namespace App\Services\Notifications;

/**
 * Built-in templates, also seeded into `notification_templates` so admins can
 * edit them (PUT /admin/notifications/templates/{id}). Email bodies are HTML
 * fragments wrapped by resources/views/emails/layout.blade.php.
 */
final class DefaultTemplates
{
    /** @return array<string, array{name:string, variables:list<string>, email?:array{subject:string, body:string}, sms?:string, whatsapp?:string, push?:array{subject:string, body:string}}> */
    public static function all(): array
    {
        return [
            'otp_code' => [
                'name' => 'One-time code',
                'variables' => ['code', 'purpose', 'minutes', 'firstName'],
                'email' => ['subject' => 'Your Wedpluz code: {{code}}', 'body' => '<p>Hi {{firstName}},</p><p>Use this code to {{purpose}}:</p><p style="font-size:28px;font-weight:700;letter-spacing:6px">{{code}}</p><p>The code expires in {{minutes}} minutes. If you did not request it, you can ignore this email.</p>'],
                'sms' => 'Your Wedpluz code is {{code}}. It expires in {{minutes}} minutes. Do not share it.',
                'whatsapp' => 'Your Wedpluz code is {{code}}. It expires in {{minutes}} minutes.',
            ],
            'welcome' => [
                'name' => 'Welcome',
                'variables' => ['firstName', 'appUrl'],
                'email' => ['subject' => 'Welcome to Wedpluz, {{firstName}}', 'body' => '<p>Hi {{firstName}},</p><p>Welcome to Wedpluz. Discover events, hire vendors and plan unforgettable moments.</p><p><a href="{{appUrl}}">Open Wedpluz</a></p>'],
            ],
            'password_changed' => [
                'name' => 'Password changed',
                'variables' => ['firstName'],
                'email' => ['subject' => 'Your Wedpluz password was changed', 'body' => '<p>Hi {{firstName}},</p><p>Your password was just changed and your other sessions were signed out. If this was not you, reset your password immediately and contact support.</p>'],
            ],
            'ticket_purchased' => [
                'name' => 'Ticket confirmation and receipt',
                'variables' => ['firstName', 'eventTitle', 'eventDate', 'orderReference', 'total', 'ticketCount', 'ticketsUrl'],
                'email' => ['subject' => 'Your tickets for {{eventTitle}}', 'body' => '<p>Hi {{firstName}},</p><p>Your order <strong>{{orderReference}}</strong> is confirmed.</p><p><strong>{{eventTitle}}</strong><br>{{eventDate}}</p><p>Tickets: {{ticketCount}}<br>Total paid: {{total}}</p><p><a href="{{ticketsUrl}}">View your tickets and QR codes</a></p>'],
                'sms' => 'Wedpluz: your {{ticketCount}} ticket(s) for {{eventTitle}} are confirmed. Ref {{orderReference}}.',
                'whatsapp' => 'Your {{ticketCount}} ticket(s) for {{eventTitle}} on {{eventDate}} are confirmed. Ref {{orderReference}}.',
                'push' => ['subject' => 'Tickets confirmed', 'body' => 'Your tickets for {{eventTitle}} are ready.'],
            ],
            'ticket_cancelled' => [
                'name' => 'Ticket cancelled',
                'variables' => ['firstName', 'eventTitle', 'refundText'],
                'email' => ['subject' => 'Your booking for {{eventTitle}} was cancelled', 'body' => '<p>Hi {{firstName}},</p><p>Your booking for <strong>{{eventTitle}}</strong> has been cancelled. {{refundText}}</p>'],
            ],
            'refund_updated' => [
                'name' => 'Refund update',
                'variables' => ['firstName', 'eventTitle', 'amount', 'status'],
                'email' => ['subject' => 'Refund {{status}} for {{eventTitle}}', 'body' => '<p>Hi {{firstName}},</p><p>Your refund of <strong>{{amount}}</strong> for {{eventTitle}} is {{status}}.</p>'],
            ],
            'event_cancelled' => [
                'name' => 'Event cancelled',
                'variables' => ['firstName', 'eventTitle', 'reason'],
                'email' => ['subject' => '{{eventTitle}} has been cancelled', 'body' => '<p>Hi {{firstName}},</p><p>The organiser cancelled <strong>{{eventTitle}}</strong>. Reason: {{reason}}</p><p>Paid orders are refunded automatically in full.</p>'],
                'sms' => 'Wedpluz: {{eventTitle}} has been cancelled. Paid orders are refunded automatically.',
            ],
            'event_updated' => [
                'name' => 'Event updated',
                'variables' => ['firstName', 'eventTitle', 'changes'],
                'email' => ['subject' => 'Update to {{eventTitle}}', 'body' => '<p>Hi {{firstName}},</p><p>The organiser updated <strong>{{eventTitle}}</strong>: {{changes}}</p>'],
            ],
            'event_reminder' => [
                'name' => 'Event reminder',
                'variables' => ['firstName', 'eventTitle', 'eventDate', 'when'],
                'email' => ['subject' => 'Reminder: {{eventTitle}} {{when}}', 'body' => '<p>Hi {{firstName}},</p><p><strong>{{eventTitle}}</strong> starts {{when}} ({{eventDate}}). Have your QR code ready.</p>'],
                'whatsapp' => 'Reminder: {{eventTitle}} starts {{when}} ({{eventDate}}).',
            ],
            'collaboration_invite' => [
                'name' => 'Collaboration invite',
                'variables' => ['firstName', 'inviterName', 'eventTitle', 'role', 'appUrl'],
                'email' => ['subject' => '{{inviterName}} invited you to {{eventTitle}}', 'body' => '<p>Hi {{firstName}},</p><p>{{inviterName}} invited you to join <strong>{{eventTitle}}</strong> as {{role}}.</p><p><a href="{{appUrl}}">Accept or decline</a></p>'],
            ],
            'identity_result' => [
                'name' => 'Identity verification result',
                'variables' => ['firstName', 'status', 'reason'],
                'email' => ['subject' => 'Identity verification {{status}}', 'body' => '<p>Hi {{firstName}},</p><p>Your identity verification is {{status}}. {{reason}}</p>'],
            ],
            'wallet_activity' => [
                'name' => 'Wallet activity',
                'variables' => ['firstName', 'description', 'amount', 'balance'],
                'email' => ['subject' => 'Wallet: {{description}}', 'body' => '<p>Hi {{firstName}},</p><p>{{description}}: <strong>{{amount}}</strong>.</p><p>Available balance: {{balance}}</p>'],
            ],
            'account_deletion' => [
                'name' => 'Account deletion requested',
                'variables' => ['firstName', 'scheduledFor'],
                'email' => ['subject' => 'Your Wedpluz account will be deleted', 'body' => '<p>Hi {{firstName}},</p><p>We received your request to delete your account. It will be permanently deleted on {{scheduledFor}}. Sign in before then to cancel the request.</p>'],
            ],
            'admin_invite' => [
                'name' => 'Administrator invite',
                'variables' => ['firstName', 'inviteUrl', 'hours'],
                'email' => ['subject' => 'You have been invited to the Wedpluz admin console', 'body' => '<p>Hi {{firstName}},</p><p>You have been invited as a Wedpluz administrator. Set your password and enrol two-factor authentication within {{hours}} hours:</p><p><a href="{{inviteUrl}}">Accept invitation</a></p>'],
            ],
            'support_reply' => [
                'name' => 'Support reply',
                'variables' => ['firstName', 'reference', 'body'],
                'email' => ['subject' => 'Re: support ticket {{reference}}', 'body' => '<p>Hi {{firstName}},</p><p>{{body}}</p><p>Reference: {{reference}}</p>'],
            ],
            'generic' => [
                'name' => 'Generic notification',
                'variables' => ['firstName', 'title', 'body', 'link'],
                'email' => ['subject' => '{{title}}', 'body' => '<p>Hi {{firstName}},</p><p>{{body}}</p>'],
                'sms' => '{{title}}: {{body}}',
                'whatsapp' => '{{title}}: {{body}}',
                'push' => ['subject' => '{{title}}', 'body' => '{{body}}'],
            ],
        ];
    }

    /** @return array{subject:?string, body:string, variables:list<string>}|null */
    public static function get(string $key, string $channel): ?array
    {
        $template = self::all()[$key] ?? null;
        if ($template === null || ! isset($template[$channel])) {
            return null;
        }
        $value = $template[$channel];

        return is_array($value)
            ? ['subject' => $value['subject'], 'body' => $value['body'], 'variables' => $template['variables']]
            : ['subject' => null, 'body' => $value, 'variables' => $template['variables']];
    }
}
