<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · Wedpluz</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $url }}">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Wedpluz">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $url }}">
    <meta property="og:image" content="{{ $image }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ $title }}">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $image }}">

    <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #faf7fa; color: #270427; }
        main { max-width: 640px; margin: 0 auto; padding: 24px 16px; }
        img { width: 100%; height: auto; border-radius: 12px; display: block; }
        h1 { font-size: 1.5rem; margin: 16px 0 4px; }
        p { line-height: 1.5; }
        .facts { color: #6b4a6b; margin: 0 0 12px; }
        a.cta { display: inline-block; background: #9b119b; color: #fff; padding: 10px 18px; border-radius: 8px; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
<main>
    <img src="{{ $image }}" alt="{{ $title }}" width="1200" height="630">
    <h1>{{ $title }}</h1>
    @if ($facts)
        <p class="facts">{{ implode(' · ', $facts) }}</p>
    @endif
    <p>{{ $description }}</p>
    <p><a class="cta" href="{{ $url }}">{{ $cta }}</a></p>
</main>
</body>
</html>
