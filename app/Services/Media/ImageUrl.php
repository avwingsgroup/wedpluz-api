<?php

namespace App\Services\Media;

/**
 * Builds optimised Cloudinary delivery URLs for images.
 *
 * - A Cloudinary image URL on our cloud has its existing transformations
 *   replaced with the requested preset (so stored URLs never lock in a size).
 * - A remote https image (e.g. a Google avatar) is served through Cloudinary
 *   fetch delivery, so it is cached, resized and converted like our own media.
 * - Local URLs (development storage, *.test hosts), videos, raw files and
 *   other clouds are returned unchanged.
 *
 * Without Cloudinary credentials every URL is returned unchanged.
 */
class ImageUrl
{
    /** Cloudinary transformation parameter names (first segment parts look like `w_400`). */
    private const PARAM = '(?:w|h|c|g|q|f|e|r|a|x|y|z|b|o|l|u|t|d|dpr|ar|so|eo|du|fl|bo|co|fps|pg|dn|cs|if|vc|ac|br|sp|ki|\$[a-z0-9_]+)';

    /** @param array<string,string> $presets */
    public function __construct(
        private readonly ?string $cloudName,
        private readonly bool $configured,
        private readonly string $folder,
        private readonly array $presets,
        private readonly bool $fetchRemote = false,
    ) {}

    public static function fromConfig(): self
    {
        $c = (array) config('services.cloudinary');

        return new self(
            $c['cloud_name'] ?? null,
            ! empty($c['cloud_name']) && ! empty($c['api_key']) && ! empty($c['api_secret']),
            trim((string) ($c['folder'] ?? 'wedpluz'), '/'),
            (array) config('wedpluz.images.presets', []),
            (bool) ($c['fetch_remote'] ?? false),
        );
    }

    public function enabled(): bool
    {
        return $this->configured;
    }

    public function transformation(string $preset): string
    {
        return $this->presets[$preset] ?? $this->presets['media'] ?? 'f_auto,q_auto';
    }

    /** Is this an image delivered from our Cloudinary cloud? */
    public function isCloudinaryImage(?string $url): bool
    {
        return $this->configured && $url !== null
            && (bool) preg_match('#^https?://res\.cloudinary\.com/'.preg_quote((string) $this->cloudName, '#').'/image/(upload|fetch)/#', $url);
    }

    /**
     * @param  bool  $allowRemote  serve non-Cloudinary https images through fetch delivery
     */
    public function transform(?string $url, string $preset, bool $allowRemote = true): ?string
    {
        if (! $this->configured || $url === null || $url === '') {
            return $url;
        }
        $transformation = $this->transformation($preset);
        $base = 'https://res.cloudinary.com/'.$this->cloudName.'/image/';

        if (preg_match('#^https?://res\.cloudinary\.com/'.preg_quote((string) $this->cloudName, '#').'/image/upload/(.+)$#', $url, $m)) {
            return $base.'upload/'.$transformation.'/'.$this->stripTransformations($m[1]);
        }
        if (preg_match('#^https?://res\.cloudinary\.com/'.preg_quote((string) $this->cloudName, '#').'/image/fetch/(.+)$#', $url, $m)) {
            return $base.'fetch/'.$transformation.'/'.$this->stripTransformations($m[1]);
        }
        if ($allowRemote && $this->fetchRemote && $this->isPublicRemote($url)) {
            return $base.'fetch/'.$transformation.'/'.rawurlencode($url);
        }

        return $url;
    }

    /** Should this URL be copied into Cloudinary (remote, public, not already on a CDN we manage)? */
    public function shouldImport(?string $url): bool
    {
        return $this->configured && $url !== null && $this->isPublicRemote($url);
    }

    /** Delivery URL for a public ID in our root folder, e.g. `static/event-placeholder`. */
    public function forPublicId(string $publicId, string $preset): ?string
    {
        if (! $this->configured) {
            return null;
        }

        return 'https://res.cloudinary.com/'.$this->cloudName.'/image/upload/'.$this->transformation($preset).'/'.ltrim($this->folder.'/'.$publicId, '/');
    }

    public function folder(): string
    {
        return $this->folder;
    }

    /** Drop leading transformation segments (`f_auto,q_auto/`, `w_400/`) and keep version + public ID. */
    private function stripTransformations(string $path): string
    {
        $segments = explode('/', $path);
        $part = self::PARAM.'_[^,/]+';
        while (count($segments) > 1 && preg_match('#^'.$part.'(?:,'.$part.')*$#', $segments[0])) {
            array_shift($segments);
        }

        return implode('/', $segments);
    }

    /** https URL on a public host (Cloudinary cannot fetch localhost, private IPs or *.test). */
    public function isPublicRemote(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        // Other Cloudinary URLs (videos, raw files, other clouds) are already CDN-delivered.
        if (! str_starts_with($url, 'https://') || $host === '' || $host === 'localhost' || str_ends_with($host, 'cloudinary.com')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        foreach (['.test', '.local', '.localhost', '.internal', '.lan'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return $host !== $appHost;
    }
}
