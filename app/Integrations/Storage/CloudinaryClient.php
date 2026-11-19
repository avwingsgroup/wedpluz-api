<?php

namespace App\Integrations\Storage;

use App\Integrations\Support\IntegrationException;
use App\Integrations\Support\ProviderHttp;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Cloudinary Upload and Admin APIs with signed requests (SHA-1 signature of
 * the sorted parameters + API secret). Returns delivery URLs with automatic
 * format and quality (`f_auto,q_auto`) so clients can request responsive sizes.
 */
class CloudinaryClient
{
    public function __construct(private readonly array $config) {}

    public function isConfigured(): bool
    {
        return ! empty($this->config['cloud_name']) && ! empty($this->config['api_key']) && ! empty($this->config['api_secret']);
    }

    /**
     * @return array{public_id:string, resource_type:string, secure_url:string, format:?string, width:?int, height:?int, bytes:int, duration:?float}
     */
    public function upload(string $path, string $folder, string $publicId, string $resourceType = 'auto', bool $overwrite = false): array
    {
        return $this->send($folder, $publicId, $resourceType, $overwrite, fn (PendingRequest $http, string $url, array $params) => $http
            ->attach('file', fopen($path, 'rb'), basename($path))->post($url, $params));
    }

    /**
     * Import a remote image by URL (Cloudinary downloads it), e.g. a social
     * sign-in avatar, so it is stored and served from our cloud.
     *
     * @return array{public_id:string, resource_type:string, secure_url:string, format:?string, width:?int, height:?int, bytes:int, duration:?float}
     */
    public function uploadRemote(string $remoteUrl, string $folder, string $publicId, bool $overwrite = true): array
    {
        return $this->send($folder, $publicId, 'image', $overwrite, fn (PendingRequest $http, string $url, array $params) => $http
            ->asForm()->post($url, $params + ['file' => $remoteUrl]));
    }

    /** @param callable(PendingRequest, string, array<string,string>): Response $post */
    private function send(string $folder, string $publicId, string $resourceType, bool $overwrite, callable $post): array
    {
        $this->ensureConfigured();
        $params = [
            'folder' => trim(($this->config['folder'] ?? 'wedpluz').'/'.$folder, '/'),
            'public_id' => $publicId,
            'overwrite' => $overwrite ? 'true' : 'false',
            'timestamp' => (string) time(),
        ];
        // Incoming transformation: cap stored image originals; delivery presets handle sizes per screen.
        if ($resourceType === 'image' && ! empty($this->config['incoming_transformation'])) {
            $params['transformation'] = (string) $this->config['incoming_transformation'];
        }
        if ($overwrite) {
            $params['invalidate'] = 'true';
        }
        $params['signature'] = $this->sign($params);
        $params['api_key'] = $this->config['api_key'];

        $url = sprintf('https://api.cloudinary.com/v1_1/%s/%s/upload', $this->config['cloud_name'], $resourceType);
        $response = ProviderHttp::for('cloudinary', (int) ($this->config['timeout'] ?? 120), 1)->call(
            'upload',
            fn (PendingRequest $http) => $post($http, $url, $params),
            ['folder' => $params['folder'], 'resource_type' => $resourceType],
        );

        if (! $response->successful()) {
            throw IntegrationException::fromStatus('cloudinary', 'upload', $response->status(), (string) ($response->json('error.message') ?? 'Upload failed.'));
        }

        return [
            'public_id' => (string) $response->json('public_id'),
            'resource_type' => (string) $response->json('resource_type'),
            'secure_url' => (string) $response->json('secure_url'),
            'format' => $response->json('format'),
            'width' => $response->json('width'),
            'height' => $response->json('height'),
            'bytes' => (int) $response->json('bytes'),
            'duration' => $response->json('duration'),
        ];
    }

    public function destroy(string $publicId, string $resourceType = 'image'): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }
        $params = ['public_id' => $publicId, 'timestamp' => (string) time(), 'invalidate' => 'true'];
        $params['signature'] = $this->sign($params);
        $params['api_key'] = $this->config['api_key'];

        $url = sprintf('https://api.cloudinary.com/v1_1/%s/%s/destroy', $this->config['cloud_name'], $resourceType);
        $response = ProviderHttp::for('cloudinary', 30, 1)->call('destroy', fn (PendingRequest $http) => $http->asForm()->post($url, $params));

        return $response->successful() && in_array($response->json('result'), ['ok', 'not found'], true);
    }

    /** Admin API ping: true when the cloud name, key and secret are accepted. */
    public function ping(): bool
    {
        $this->ensureConfigured();
        $url = sprintf('https://api.cloudinary.com/v1_1/%s/ping', $this->config['cloud_name']);

        return ProviderHttp::for('cloudinary', 15, 0)->call(
            'ping',
            fn (PendingRequest $http) => $http->withBasicAuth($this->config['api_key'], $this->config['api_secret'])->get($url),
        )->successful();
    }

    /** Admin API lookup, used to confirm direct (signed) uploads before attaching them. */
    public function resource(string $publicId, string $resourceType = 'image'): ?array
    {
        $this->ensureConfigured();
        $url = sprintf('https://api.cloudinary.com/v1_1/%s/resources/%s/upload/%s', $this->config['cloud_name'], $resourceType, rawurlencode($publicId));
        $response = ProviderHttp::for('cloudinary', 20, 1)->call(
            'resource',
            fn (PendingRequest $http) => $http->withBasicAuth($this->config['api_key'], $this->config['api_secret'])->get($url),
        );

        return $response->successful() ? $response->json() : null;
    }

    /** Signed fields for a direct browser upload (POST /uploads/sign). */
    public function signedUploadFields(string $folder, string $publicId, int $ttlSeconds = 3600): array
    {
        $this->ensureConfigured();
        $params = [
            'folder' => trim(($this->config['folder'] ?? 'wedpluz').'/'.$folder, '/'),
            'public_id' => $publicId,
            'timestamp' => (string) time(),
        ];
        $params['signature'] = $this->sign($params);
        $params['api_key'] = $this->config['api_key'];

        return [
            'uploadUrl' => sprintf('https://api.cloudinary.com/v1_1/%s/auto/upload', $this->config['cloud_name']),
            'fields' => $params,
            'publicUrlTemplate' => sprintf('https://res.cloudinary.com/%s/{resourceType}/upload/f_auto,q_auto/%s/%s', $this->config['cloud_name'], $params['folder'], $publicId),
        ];
    }

    /** Delivery URL with transformations, e.g. `f_auto,q_auto,w_400,h_400,c_fill`. */
    public function url(string $publicId, string $resourceType = 'image', string $transformation = 'f_auto,q_auto'): string
    {
        return sprintf('https://res.cloudinary.com/%s/%s/upload/%s/%s', $this->config['cloud_name'], $resourceType, $transformation, $publicId);
    }

    public function thumbnailUrl(string $publicId, string $resourceType = 'image'): string
    {
        return $resourceType === 'video'
            ? sprintf('https://res.cloudinary.com/%s/video/upload/so_0,w_480,h_480,c_limit,f_jpg/%s.jpg', $this->config['cloud_name'], $publicId)
            : $this->url($publicId, 'image', 'f_auto,q_auto,w_480,h_480,c_limit');
    }

    /** @param array<string,string> $params */
    public function sign(array $params): string
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value === '' || $value === null || in_array($key, ['file', 'api_key', 'resource_type', 'cloud_name'], true)) {
                continue;
            }
            $pairs[] = $key.'='.$value;
        }

        return sha1(implode('&', $pairs).$this->config['api_secret']);
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw IntegrationException::notConfigured('cloudinary');
        }
    }
}
