<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $status === 410 ? 'No longer available' : 'Not found' }} · Wedpluz</title>
    <meta name="robots" content="noindex">
    <meta property="og:title" content="Wedpluz">
    <meta property="og:description" content="Events, tickets and trusted vendors for every celebration.">
</head>
<body style="font-family: system-ui, sans-serif; padding: 24px 16px;">
    <p>{{ $status === 410 ? 'This page is no longer available.' : 'This page could not be found.' }}</p>
    <p><a href="{{ $home }}">Go to Wedpluz</a></p>
</body>
</html>
