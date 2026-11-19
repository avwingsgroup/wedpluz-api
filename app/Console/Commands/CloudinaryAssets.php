<?php

namespace App\Console\Commands;

use App\Integrations\Storage\CloudinaryClient;
use App\Services\Media\ImageUrl;
use Illuminate\Console\Command;

/**
 * Generates the platform's static images and uploads them to Cloudinary
 * (`<root folder>/static/...`), so every image the API serves is managed
 * there. Safe to re-run: assets are overwritten and the CDN cache invalidated.
 * A local copy is written to public/images as the fallback when Cloudinary is
 * not configured.
 */
class CloudinaryAssets extends Command
{
    protected $signature = 'wedpluz:cloudinary-assets {--check : Only verify the Cloudinary credentials}';

    protected $description = 'Verify Cloudinary credentials and upload the static platform images';

    public function handle(CloudinaryClient $cloudinary, ImageUrl $images): int
    {
        $local = public_path('images/event-placeholder.jpg');
        if (! is_dir(dirname($local))) {
            mkdir(dirname($local), 0775, true);
        }
        $this->placeholder($local);
        $this->line('Generated '.$local);

        if (! $cloudinary->isConfigured()) {
            $this->warn('Cloudinary is not configured (CLOUDINARY_CLOUD_NAME / API_KEY / API_SECRET); using the local placeholder.');

            return self::SUCCESS;
        }

        if ($this->option('check')) {
            return $this->verify($cloudinary);
        }

        $result = $cloudinary->upload($local, 'static', 'event-placeholder', 'image', overwrite: true);
        $this->info('Uploaded '.$result['public_id'].' ('.$result['width'].'x'.$result['height'].', '.round($result['bytes'] / 1024).' KB)');
        $this->line('Placeholder cover: '.$images->forPublicId('static/event-placeholder', 'cover'));

        return self::SUCCESS;
    }

    private function verify(CloudinaryClient $cloudinary): int
    {
        if (! $cloudinary->ping()) {
            $this->error('Cloudinary rejected the credentials. Check CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY and CLOUDINARY_API_SECRET.');

            return self::FAILURE;
        }
        $this->info('Cloudinary credentials accepted.');

        return self::SUCCESS;
    }

    /** 1600x900 brand gradient (purple #9b119b to plum #270427) with soft rings. */
    private function placeholder(string $path): void
    {
        [$w, $h] = [1600, 900];
        $img = imagecreatetruecolor($w, $h);
        [$r1, $g1, $b1] = [0x9B, 0x11, 0x9B];
        [$r2, $g2, $b2] = [0x27, 0x04, 0x27];
        for ($x = 0; $x < $w; $x++) {
            for ($y = 0; $y < $h; $y += 4) {
                $t = min(1, ($x / $w) * 0.6 + ($y / $h) * 0.4);
                $c = imagecolorallocate($img, (int) ($r1 + ($r2 - $r1) * $t), (int) ($g1 + ($g2 - $g1) * $t), (int) ($b1 + ($b2 - $b1) * $t));
                imagefilledrectangle($img, $x, $y, $x, $y + 3, $c);
            }
        }
        imagesetthickness($img, 3);
        $ring = imagecolorallocatealpha($img, 255, 255, 255, 110);
        foreach ([[1250, 220, 520], [1250, 220, 760], [260, 760, 420]] as [$cx, $cy, $d]) {
            imageellipse($img, $cx, $cy, $d, $d, $ring);
        }
        imagejpeg($img, $path, 88);
        imagedestroy($img);
    }
}
