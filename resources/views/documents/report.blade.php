<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #270427; }
        h1 { font-size: 16px; color: #9b119b; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #eee; padding: 4px; text-align: left; }
        th { background: #faf7fa; }
    </style>
</head>
<body>
<h1>Wedpluz report: {{ str_replace('_', ' ', $title) }}</h1>
<p>{{ $period['from'] }} to {{ $period['to'] }}</p>
<table>
    <thead><tr>@foreach($header as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
    <tbody>
    @foreach($rows as $row)
        <tr>@foreach($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
