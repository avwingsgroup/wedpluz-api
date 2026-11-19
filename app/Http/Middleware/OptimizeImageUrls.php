<?php

namespace App\Http\Middleware;

use App\Services\Media\ImageUrl;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rewrites every image URL in JSON responses to an optimised Cloudinary
 * delivery URL (format, quality, size) chosen by field name — see
 * config/wedpluz.php `images`. Presenters keep returning the stored URL;
 * this is the single place that decides how images are delivered.
 *
 * - Known image fields (avatarUrl, coverUrl, imageUrl, thumbnailUrl, gallery,
 *   logoUrl…) get their preset; remote images in those fields go through
 *   Cloudinary fetch.
 * - Any other string that is a Cloudinary image URL on our cloud (media `url`,
 *   `mediaUrl`, attachment URLs) gets the generic `media` preset.
 * - Templates and non-image URLs are untouched.
 */
class OptimizeImageUrls
{
    private const SKIP_KEYS = ['publicUrlTemplate', 'uploadUrl', 'fields', 'signature'];

    /** @var array<string,string> */
    private array $fields;

    public function __construct(private readonly ImageUrl $images)
    {
        $this->fields = (array) config('wedpluz.images.fields', []);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse || ! $this->images->enabled() || ! $this->worthScanning((string) $response->getContent())) {
            return $response;
        }

        $response->setData($this->walk($response->getData(true)));

        return $response;
    }

    private function worthScanning(string $json): bool
    {
        if (str_contains($json, 'res.cloudinary.com')) {
            return true;
        }
        foreach (array_keys($this->fields) as $field) {
            if (str_contains($json, '"'.$field.'":"http') || str_contains($json, '"'.$field.'":["http')) {
                return true;
            }
        }

        return false;
    }

    private function walk(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            if ($key !== null && in_array($key, self::SKIP_KEYS, true)) {
                return $value;
            }
            if ($key !== null && isset($this->fields[$key]) && array_is_list($value)) {
                return array_map(fn ($v) => is_string($v) ? $this->images->transform($v, $this->fields[$key]) : $this->walk($v), $value);
            }
            foreach ($value as $k => $v) {
                $value[$k] = $this->walk($v, is_string($k) ? $k : $key);
            }

            return $value;
        }

        if (! is_string($value) || $key === null || in_array($key, self::SKIP_KEYS, true)) {
            return $value;
        }
        if (isset($this->fields[$key])) {
            return $this->images->transform($value, $this->fields[$key]);
        }
        if ($this->images->isCloudinaryImage($value)) {
            return $this->images->transform($value, 'media', allowRemote: false);
        }

        return $value;
    }
}
