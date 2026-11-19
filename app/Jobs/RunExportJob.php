<?php

namespace App\Jobs;

use App\Models\ExportJob;
use App\Services\Admin\ReportService;
use App\Support\XlsxWriter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Generates a report export (CSV, XLSX or PDF) to the private disk. Files are
 * served only through 15-minute signed URLs and expire after 7 days. Cells are
 * neutralised against spreadsheet formula injection.
 */
class RunExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public readonly string $exportId)
    {
        // Long-running: separate connection whose retry_after exceeds $timeout (config/queue.php).
        $this->onConnection(config('queue.long_connection'))->onQueue('long');
        $this->afterCommit();
    }

    public function handle(ReportService $reports): void
    {
        $job = ExportJob::query()->find($this->exportId);
        if ($job === null || $job->status !== 'queued') {
            return;
        }
        $job->forceFill(['status' => 'processing'])->save();

        try {
            $q = $job->query;
            $result = $reports->run($job->report_key, $q['from'], $q['to'], $q['groupBy'] ?? null, $q['filters'] ?? []);
            $header = array_column($result['columns'], 'label');
            $keys = array_column($result['columns'], 'key');
            $rows = array_map(fn ($r) => array_map(fn ($k) => self::safe($r[$k] ?? ''), $keys), $result['rows']);
            $path = 'exports/'.$job->id.'.'.$job->format;

            $content = match ($job->format) {
                'csv' => self::csv($header, $rows),
                'xlsx' => XlsxWriter::build($header, $rows),
                'pdf' => Pdf::loadView('documents.report', ['title' => $result['key'], 'period' => $result['period'], 'header' => $header, 'rows' => $rows])->setPaper('a4', 'landscape')->output(),
            };
            Storage::disk('private')->put($path, $content);
            $job->forceFill(['status' => 'ready', 'file_path' => $path, 'expires_at' => now()->addDays(7)])->save();
        } catch (\Throwable $e) {
            $job->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)])->save();
            throw $e;
        }
    }

    /** Neutralise CSV/XLSX formula injection (=, +, -, @, tab, CR). */
    public static function safe(mixed $value): string|int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    private static function csv(array $header, array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $header);
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        rewind($fh);

        return (string) stream_get_contents($fh);
    }
}
