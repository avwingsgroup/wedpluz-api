<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Receipt {{ $order->reference }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #270427; font-size: 12px; }
        .brand { color: #9b119b; font-size: 22px; font-weight: bold; }
        h1 { font-size: 18px; margin: 16px 0 4px; }
        .muted { color: #6b5a6b; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { padding: 8px 4px; border-bottom: 1px solid #eee; text-align: left; }
        td.num, th.num { text-align: right; }
        .total td { font-weight: bold; border-bottom: none; }
    </style>
</head>
<body>
<div class="brand">Wedpluz</div>
<h1>Payment receipt</h1>
<div class="muted">Reference {{ $order->reference }} &middot; Paid {{ $paidAt }}</div>
<p>
    {{ $order->attendee['firstName'] ?? '' }} {{ $order->attendee['lastName'] ?? '' }}<br>
    {{ $order->attendee['email'] ?? '' }}
</p>
<p><strong>{{ $order->event->title }}</strong></p>
<table>
    <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Unit</th><th class="num">Amount</th></tr></thead>
    <tbody>
    @foreach($order->lines as $line)
        <tr><td>{{ $line->name_snapshot }}</td><td class="num">{{ $line->quantity }}</td><td class="num">{{ $money($line->unit_price) }}</td><td class="num">{{ $money($line->subtotal) }}</td></tr>
    @endforeach
    @foreach($order->foodLines as $line)
        <tr><td>{{ $line->name_snapshot }}</td><td class="num">{{ $line->quantity }}</td><td class="num">{{ $money($line->unit_price) }}</td><td class="num">{{ $money($line->subtotal) }}</td></tr>
    @endforeach
    <tr><td colspan="3">Subtotal</td><td class="num">{{ $money($order->subtotal) }}</td></tr>
    @if($order->discount > 0)
        <tr><td colspan="3">Discount</td><td class="num">-{{ $money($order->discount) }}</td></tr>
    @endif
    <tr><td colspan="3">Service fee</td><td class="num">{{ $money($order->service_fee) }}</td></tr>
    <tr class="total"><td colspan="3">Total paid</td><td class="num">{{ $money($order->total) }}</td></tr>
    </tbody>
</table>
<p class="muted">Payment method: {{ ucfirst(str_replace('_', ' ', $order->payment_method)) }}</p>
</body>
</html>
