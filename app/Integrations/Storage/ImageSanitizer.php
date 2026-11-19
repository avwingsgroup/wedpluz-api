<?php

namespace App\Integrations\Storage;

/**
 * Strips EXIF metadata (including GPS location) from JPEG and WebP images by
 * re-encoding them with GD, after applying the EXIF orientation so the image
 * still displays upright. PNG carries no EXIF GPS in practice and is left as-is.
 */
class ImageSanitizer
{
    public function strip(string $path, string $mime): void
    {
        if (! function_exists('imagecreatefromjpeg')) {
            return;
        }

        try {
            if ($mime === 'image/jpeg') {
                $image = @imagecreatefromjpeg($path);
                if ($image === false) {
                    return;
                }
                $image = $this->orient($image, $path);
                imagejpeg($image, $path, 90);
                imagedestroy($image);
            } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
                $image = @imagecreatefromwebp($path);
                if ($image === false) {
                    return;
                }
                imagewebp($image, $path, 90);
                imagedestroy($image);
            }
        } catch (\Throwable) {
            // A malformed image will be rejected downstream; never block on sanitising.
        }
    }

    /** @param \GdImage $image */
    private function orient($image, string $path)
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        return match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
    }
}
