<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Ticket {{ $ticket->code }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #270427; margin: 0; }
        .card { border: 2px solid #9b119b; border-radius: 14px; padding: 22px; margin: 12px; }
        .brand { color: #9b119b; font-size: 20px; font-weight: bold; }
        h1 { font-size: 20px; margin: 10px 0 4px; }
        .muted { color: #6b5a6b; font-size: 12px; }
        .qr { text-align: center; margin: 18px 0; }
        .code { text-align: center; font-size: 22px; letter-spacing: 3px; font-weight: bold; }
        table { width: 100%; font-size: 12px; margin-top: 12px; }
        td { padding: 3px 0; }
    </style>
</head>
<body>
<div class="card">
    <div class="brand">Wedpluz</div>
    <h1>{{ $event->title }}</h1>
    <div class="muted">{{ $starts }} @if($event->city) &middot; {{ $event->city }} @endif</div>
    <div class="qr"><img src="{{ $qr }}" width="200" height="200" alt="QR code"></div>
    <div class="code">{{ $ticket->code }}</div>
    <table>
        <tr><td class="muted">Holder</td><td>{{ $ticket->holder_name }}</td></tr>
        <tr><td class="muted">Ticket</td><td>{{ $ticket->is_additional_guest ? 'Guest' : ($ticket->ticketType?->name ?? 'Ticket') }}</td></tr>
        @if($ticket->seatReservation)
            <tr><td class="muted">Seat</td><td>{{ $ticket->seatReservation->table_label }} &middot; {{ $ticket->seatReservation->seat_label }}</td></tr>
        @endif
        <tr><td class="muted">Order</td><td>{{ $ticket->order->reference }}</td></tr>
    </table>
    <p class="muted">Show this QR code at the entrance. Each code admits one person once.</p>
</div>
</body>
</html>
