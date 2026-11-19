<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Fmt;
use App\Jobs\RunExportJob;
use App\Models\ExportJob;
use App\Services\Admin\AuditLogger;
use App\Services\Admin\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class AdminReportsController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function definitions(): JsonResponse
    {
        return $this->ok($this->reports->catalogue());
    }

    public function run(Request $request, string $key): JsonResponse
    {
        $data = $this->validateQuery($request);

        return $this->ok($this->reports->run($key, $data['from'], $data['to'], $data['groupBy'] ?? null, $data['filters'] ?? []));
    }

    public function export(Request $request, string $key, AuditLogger $audit): JsonResponse
    {
        $data = $this->validateQuery($request) + $request->validate(['format' => ['required', 'in:csv,xlsx,pdf']]);
        if (! array_key_exists($key, $this->reports->definitions())) {
            throw ApiException::notFound('Unknown report.');
        }
        $job = ExportJob::query()->create([
            'admin_user_id' => $this->admin()->id,
            'report_key' => $key,
            'query' => ['from' => $data['from'], 'to' => $data['to'], 'groupBy' => $data['groupBy'] ?? null, 'filters' => $data['filters'] ?? []],
            'format' => $data['format'],
            'status' => 'queued',
        ]);
        RunExportJob::dispatch($job->id);
        // Exports may contain personal data: record who exported what, with the filters used.
        $audit->log('report.exported', 'reports', $job, $key, null, $job->query + ['format' => $job->format]);

        return $this->ok($this->resource($job->refresh()));
    }

    public function show(string $id): JsonResponse
    {
        return $this->ok($this->resource(ExportJob::query()->where('admin_user_id', $this->admin()->id)->findOrFail($id)));
    }

    public function download(Request $request, string $id)
    {
        if (! $request->hasValidSignature()) {
            throw ApiException::forbidden('This download link has expired.');
        }
        $job = ExportJob::query()->findOrFail($id);
        if ($job->status !== 'ready' || ! $job->file_path || ($job->expires_at && $job->expires_at->isPast())) {
            throw ApiException::notFound('This export is not available.');
        }

        return Storage::disk('private')->download($job->file_path, 'wedpluz-'.$job->report_key.'.'.$job->format);
    }

    private function resource(ExportJob $job): array
    {
        $ready = $job->status === 'ready' && $job->file_path && (! $job->expires_at || $job->expires_at->isFuture());
        $url = null;
        if ($ready) {
            $url = config('filesystems.disks.private.driver') === 's3'
                ? Storage::disk('private')->temporaryUrl($job->file_path, now()->addMinutes(15))
                : URL::temporarySignedRoute('admin.exports.download', now()->addMinutes(15), ['id' => $job->id]);
        }

        return ['id' => $job->id, 'status' => $job->status, 'format' => $job->format, 'downloadUrl' => $url, 'expiresAt' => Fmt::date($job->expires_at)];
    }

    private function validateQuery(Request $request): array
    {
        return $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'groupBy' => ['nullable', 'in:city,organizer,category,day,week,month,source,provider,status'],
            'filters' => ['sometimes', 'array'],
            'filters.*' => ['nullable', 'string', 'max:100'],
        ]);
    }
}
