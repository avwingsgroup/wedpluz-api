<?php

namespace App\Services\Notifications;

/**
 * Every NotificationType from the contract with its preference topic, action,
 * request flag, whether it is transactional (always emailed, ignores
 * opt-outs), the email template and the in-app sentence pattern. Tokens like
 * `:event` in the pattern become emphasised `segments` (bold in the UI).
 */
final class NotificationCatalog
{
    /** @return array{topic:string, action:string, isRequest:bool, transactional:bool, title:string, pattern:string, email:?string, sms:bool, whatsapp:bool} */
    public static function get(string $type): array
    {
        $defs = [
            'manager_invite' => ['collaboration', 'accept_decline', true, false, 'Manager invite', ':actor invited you to manage :event', 'collaboration_invite'],
            'vendor_invite' => ['collaboration', 'accept_decline', true, false, 'Vendor invite', ':actor invited you to work on :event', 'collaboration_invite'],
            'collaboration_invite' => ['collaboration', 'accept_decline', true, false, 'Collaboration invite', ':actor invited you to collaborate on :event', 'collaboration_invite'],
            'manager_invite_accepted' => ['collaboration', 'view', false, false, 'Invite accepted', ':actor accepted your invite to :event', null],
            'collaboration_declined' => ['collaboration', 'none', false, false, 'Invite declined', ':actor declined your invite to :event', null],
            'event_request_received' => ['collaboration', 'view', false, false, 'New attendance request', ':actor requested to attend :event', null],
            'event_request_approved' => ['ticketUpdates', 'register', false, false, 'Request approved', 'Your request to attend :event was approved', 'generic'],
            'event_request_declined' => ['ticketUpdates', 'none', false, false, 'Request declined', 'Your request to attend :event was declined', null],
            'task_assigned' => ['collaboration', 'view', false, false, 'New task', ':actor assigned you :task on :event', null],
            'ticket_purchased' => ['ticketUpdates', 'view', false, true, 'Tickets confirmed', 'Your tickets for :event are confirmed', 'ticket_purchased'],
            'ticket_cancelled' => ['ticketUpdates', 'view', false, true, 'Booking cancelled', 'Your booking for :event was cancelled', 'ticket_cancelled'],
            'refund_updated' => ['ticketUpdates', 'view', false, true, 'Refund update', 'Your refund for :event is :status', 'refund_updated'],
            'event_reminder' => ['eventReminders', 'view', false, false, 'Event reminder', ':event starts :when', 'event_reminder'],
            'event_updated' => ['ticketUpdates', 'view', false, false, 'Event updated', ':event has been updated', 'event_updated'],
            'event_cancelled' => ['ticketUpdates', 'view', false, true, 'Event cancelled', ':event has been cancelled', 'event_cancelled'],
            'message_request' => ['messages', 'view', true, false, 'Message request', ':actor wants to send you a message', null],
            'community_invite' => ['communityActivity', 'accept_decline', true, false, 'Community invite', ':actor invited you to join :community', null],
            'promotion_earning' => ['walletActivity', 'view', false, false, 'Promotion earnings', 'You earned :amount promoting :event', null],
            'wallet_credit' => ['walletActivity', 'view', false, true, 'Wallet credited', 'Your wallet was credited with :amount', 'wallet_activity'],
            'wallet_debit' => ['walletActivity', 'view', false, true, 'Wallet debited', 'Your wallet was debited :amount', 'wallet_activity'],
            'identity_verified' => ['ticketUpdates', 'none', false, true, 'Identity verified', 'Your identity has been verified', 'identity_result'],
            'identity_failed' => ['ticketUpdates', 'view', false, true, 'Identity verification failed', 'We could not verify your identity', 'identity_result'],
            'system' => ['ticketUpdates', 'none', false, false, 'Wedpluz', ':body', 'generic'],
        ];

        $d = $defs[$type] ?? $defs['system'];

        return [
            'topic' => $d[0], 'action' => $d[1], 'isRequest' => $d[2], 'transactional' => $d[3],
            'title' => $d[4], 'pattern' => $d[5], 'email' => $d[6],
            'sms' => in_array($type, ['ticket_purchased', 'event_cancelled'], true),
            'whatsapp' => in_array($type, ['ticket_purchased', 'event_reminder'], true),
        ];
    }

    /**
     * Build contract `segments` from a pattern and token values.
     *
     * @param  array<string, string>  $tokens
     * @return list<array{text:string, emphasis?:bool}>
     */
    public static function segments(string $pattern, array $tokens): array
    {
        $parts = preg_split('/(:[a-zA-Z]+)/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $segments = [];
        foreach ($parts as $part) {
            if ($part[0] === ':' && array_key_exists(substr($part, 1), $tokens)) {
                $key = substr($part, 1);
                $segments[] = $key === 'body' || $key === 'status' || $key === 'when'
                    ? ['text' => (string) $tokens[$key]]
                    : ['text' => (string) $tokens[$key], 'emphasis' => true];
            } else {
                $segments[] = ['text' => $part];
            }
        }

        return $segments;
    }

    /** @param list<array{text:string}> $segments */
    public static function plain(array $segments): string
    {
        return implode('', array_column($segments, 'text'));
    }
}
