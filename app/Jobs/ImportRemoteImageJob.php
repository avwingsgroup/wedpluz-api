<?php

namespace App\Jobs;

use App\Integrations\Storage\CloudinaryClient;
use App\Integrations\Support\IntegrationException;
use App\Services\Media\ImageUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Copies a remote image (e.g. a Google profile picture, an admin-entered
 * banner URL) into Cloudinary and points the column at the Cloudinary copy,
 * so it is optimised and resized like every other image. The column is only
 * updated if it still holds the original URL (a newer user change wins).
 */
class ImportRemoteImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly string $table,
        public readonly string $id,
        public readonly string $column,
        public readonly string $folder,
        public readonly string $remoteUrl,
    ) {
        $this->onQueue('low');
    }

    public function backoff(): array
    {
        return [30, 300];
    }

    public static function dispatchIfRemote(string $table, string $id, string $column, string $folder, ?string $url): void
    {
        if (app(ImageUrl::class)->shouldImport($url)) {
            self::dispatch($table, $id, $column, $folder, (string) $url)->afterCommit();
        }
    }

    public function handle(CloudinaryClient $cloudinary): void
    {
        try {
            $result = $cloudinary->uploadRemote($this->remoteUrl, $this->folder, $this->id);
        } catch (IntegrationException $e) {
            if ($e->transient) {
                throw $e;
            }
            Log::warning('image.import_failed', ['table' => $this->table, 'id' => $this->id, 'error' => $e->getMessage()]);

            return;
        }

        DB::table($this->table)->where('id', $this->id)->where($this->column, $this->remoteUrl)
            ->update([$this->column => $cloudinary->url($result['public_id']), 'updated_at' => now()]);
    }
}
