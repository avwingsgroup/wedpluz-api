<?php

namespace App\Services\Media;

use App\Enums\UploadPurpose;
use App\Exceptions\ApiException;
use App\Integrations\Storage\CloudinaryClient;
use App\Integrations\Storage\ImageSanitizer;
use App\Integrations\Support\IntegrationException;
use App\Models\AdminUser;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Uploads (api-contracts.md §1.6).
 *
 * - MIME type is detected from file content (finfo), never trusted from the client.
 * - Size and type limits are enforced per purpose (config/wedpluz.php).
 * - EXIF (including GPS) is stripped from images before storage.
 * - Public media goes to Cloudinary (or the local public disk when Cloudinary
 *   is not configured, e.g. in development); identity selfies and documents go
 *   to the private disk and are only served through short-lived signed URLs.
 * - Upload IDs are single-use and expire after 24 hours if never attached.
 */
class UploadService
{
    public function __construct(
        private readonly CloudinaryClient $cloudinary,
        private readonly ImageSanitizer $sanitizer,
    ) {}

    public function store(UploadedFile $file, string $purpose, User|AdminUser $owner): Upload
    {
        $rules = $this->rules($purpose);
        if (! $file->isValid()) {
            throw ApiException::validation('The upload failed. Please try again.', ['file' => ['The file could not be uploaded.']]);
        }

        $mime = (string) $file->getMimeType(); // finfo content sniffing
        if (! in_array($mime, $rules['mimes'], true)) {
            throw ApiException::validation('This file type is not allowed.', ['file' => ['The file type '.$mime.' is not allowed for '.$purpose.'.']]);
        }
        $size = (int) $file->getSize();
        if ($size > $rules['max_kb'] * 1024) {
            throw ApiException::validation('The file is too large.', ['file' => ['The file may not be larger than '.round($rules['max_kb'] / 1024).' MB.']]);
        }

        $path = $file->getRealPath();
        $isImage = str_starts_with($mime, 'image/');
        $width = $height = null;
        if ($isImage) {
            $this->sanitizer->strip($path, $mime);
            $dimensions = @getimagesize($path);
            if ($dimensions === false && $mime !== 'image/gif') {
                throw ApiException::validation('The image could not be read.', ['file' => ['The image is corrupt or unsupported.']]);
            }
            [$width, $height] = $dimensions ?: [null, null];
            $size = (int) filesize($path);
        }

        $upload = new Upload([
            'owner_user_id' => $owner instanceof User ? $owner->id : null,
            'owner_admin_id' => $owner instanceof AdminUser ? $owner->id : null,
            'purpose' => $purpose,
            'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'mime_type' => $mime,
            'size_bytes' => $size,
            'width' => $width,
            'height' => $height,
            'expires_at' => now()->addHours((int) config('wedpluz.uploads.unattached_ttl_hours', 24)),
        ]);
        $upload->id = Upload::newPrefixedId();

        if ($rules['storage'] === 'private') {
            $disk = Storage::disk('private');
            $stored = $disk->putFileAs('uploads/'.$purpose.'/'.now()->format('Y/m'), $file, $upload->id.'.'.$this->extension($mime));
            $upload->fill(['provider' => config('filesystems.disks.private.driver') === 's3' ? 's3' : 'local', 'disk_path' => $stored, 'url' => null]);
        } elseif ($this->cloudinary->isConfigured()) {
            try {
                $result = $this->cloudinary->upload($path, $purpose, $upload->id, $this->resourceType($mime));
            } catch (IntegrationException $e) {
                Log::error('upload.cloudinary_failed', ['purpose' => $purpose, 'error' => $e->getMessage()]);
                throw ApiException::validation('We could not store your file right now. Please try again.', ['file' => ['Upload failed.']]);
            }
            $upload->fill([
                'provider' => 'cloudinary',
                'provider_public_id' => $result['public_id'],
                'resource_type' => $result['resource_type'],
                'url' => $result['resource_type'] === 'raw' ? $result['secure_url'] : $this->cloudinary->url($result['public_id'], $result['resource_type']),
                'thumbnail_url' => in_array($result['resource_type'], ['image', 'video'], true) ? $this->cloudinary->thumbnailUrl($result['public_id'], $result['resource_type']) : null,
                'width' => $result['width'] ?? $width,
                'height' => $result['height'] ?? $height,
                'duration_seconds' => isset($result['duration']) ? (int) round((float) $result['duration']) : null,
            ]);
        } else {
            $stored = Storage::disk('public')->putFileAs('uploads/'.$purpose, $file, $upload->id.'.'.$this->extension($mime));
            $url = Storage::disk('public')->url($stored);
            $upload->fill(['provider' => 'local', 'disk_path' => $stored, 'url' => $url, 'thumbnail_url' => $isImage ? $url : null]);
        }

        $upload->save();

        return $upload;
    }

    /**
     * Claim an upload for a resource: owned by the caller, of an allowed purpose,
     * not expired and not already attached. Marks it attached (single use).
     *
     * @param  list<string>|string  $purposes
     */
    public function consume(string $uploadId, User|AdminUser $owner, array|string $purposes, string $field = 'uploadId'): Upload
    {
        $upload = Upload::query()->whereKey($uploadId)->lockForUpdate()->first();
        $ownerMatches = $upload !== null && ($owner instanceof User ? $upload->owner_user_id === $owner->id : $upload->owner_admin_id === $owner->id);

        if (! $ownerMatches || ! in_array($upload->purpose, (array) $purposes, true)) {
            throw ApiException::validation('Invalid upload.', [$field => ['The selected upload is invalid.']]);
        }
        if ($upload->attached_at !== null) {
            throw ApiException::validation('This upload was already used.', [$field => ['This upload has already been used.']]);
        }
        if ($upload->expires_at !== null && $upload->expires_at->isPast()) {
            throw ApiException::validation('This upload has expired.', [$field => ['The upload expired. Please upload the file again.']]);
        }
        if ($upload->provider === 'cloudinary' && $upload->url === null) {
            $this->confirmDirectUpload($upload, $field);
        }

        $upload->forceFill(['attached_at' => now(), 'expires_at' => null])->save();

        return $upload;
    }

    /** Signed form for a direct browser upload to Cloudinary (POST /uploads/sign). */
    public function signature(string $purpose, User $owner): array
    {
        $rules = $this->rules($purpose);
        if ($rules['storage'] === 'private' || ! $this->cloudinary->isConfigured()) {
            throw ApiException::validation('Direct upload is not available for this file type. Use POST /uploads.', ['purpose' => ['Direct upload is not available for this purpose.']]);
        }

        $upload = Upload::query()->create([
            'owner_user_id' => $owner->id,
            'purpose' => $purpose,
            'provider' => 'cloudinary',
            'mime_type' => 'application/octet-stream',
            'size_bytes' => 0,
            'expires_at' => now()->addHours((int) config('wedpluz.uploads.unattached_ttl_hours', 24)),
        ]);
        $signed = $this->cloudinary->signedUploadFields($purpose, $upload->id);
        $upload->forceFill(['provider_public_id' => $signed['fields']['folder'].'/'.$upload->id])->save();

        return [
            'provider' => 'cloudinary',
            'uploadUrl' => $signed['uploadUrl'],
            'fields' => $signed['fields'],
            'publicUrlTemplate' => $signed['publicUrlTemplate'],
            'expiresAt' => now()->addHour()->toIso8601ZuluString(),
            'uploadId' => $upload->id,
        ];
    }

    /** Short-lived URL for a private file (15 minutes). */
    public function temporaryUrl(Upload $upload, int $minutes = 15): ?string
    {
        if ($upload->disk_path === null) {
            return $upload->url;
        }
        if ($upload->provider === 's3') {
            return Storage::disk('private')->temporaryUrl($upload->disk_path, now()->addMinutes($minutes));
        }
        if ($upload->provider === 'local' && $upload->url !== null) {
            return $upload->url;
        }

        return URL::temporarySignedRoute('files.private', now()->addMinutes($minutes), ['upload' => $upload->id]);
    }

    public function delete(Upload $upload): void
    {
        try {
            if ($upload->provider === 'cloudinary' && $upload->provider_public_id) {
                $this->cloudinary->destroy($upload->provider_public_id, $upload->resource_type ?: 'image');
            } elseif ($upload->disk_path) {
                $disk = in_array($upload->purpose, ['identity_selfie', 'document'], true) ? 'private' : 'public';
                Storage::disk($disk)->delete($upload->disk_path);
            }
        } catch (\Throwable $e) {
            Log::warning('upload.delete_failed', ['upload' => $upload->id, 'error' => $e->getMessage()]);
        }
        $upload->delete();
    }

    /** @return array{max_kb:int, mimes:list<string>, storage:string} */
    private function rules(string $purpose): array
    {
        $rules = config('wedpluz.uploads.purposes.'.$purpose);
        if (! in_array($purpose, UploadPurpose::values(), true) || ! is_array($rules)) {
            throw ApiException::validation('Invalid upload purpose.', ['purpose' => ['The selected purpose is invalid.']]);
        }

        return $rules;
    }

    private function confirmDirectUpload(Upload $upload, string $field): void
    {
        foreach (['image', 'video', 'raw'] as $type) {
            $resource = $this->cloudinary->resource((string) $upload->provider_public_id, $type);
            if ($resource !== null) {
                $upload->forceFill([
                    'resource_type' => $type,
                    'url' => $type === 'raw' ? $resource['secure_url'] : $this->cloudinary->url($upload->provider_public_id, $type),
                    'thumbnail_url' => $type !== 'raw' ? $this->cloudinary->thumbnailUrl($upload->provider_public_id, $type) : null,
                    'mime_type' => $type.'/'.($resource['format'] ?? 'bin'),
                    'size_bytes' => (int) ($resource['bytes'] ?? 0),
                    'width' => $resource['width'] ?? null,
                    'height' => $resource['height'] ?? null,
                ]);

                return;
            }
        }
        throw ApiException::validation('The upload has not completed.', [$field => ['The file was not found. Upload it again.']]);
    }

    private function resourceType(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/'), str_starts_with($mime, 'audio/') => 'video',
            default => 'raw',
        };
    }

    private function extension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
            'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm',
            'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/ogg' => 'ogg', 'audio/webm' => 'weba', 'audio/wav' => 'wav',
            'application/pdf' => 'pdf', 'application/zip' => 'zip', 'text/plain' => 'txt',
            default => 'bin',
        };
    }
}
