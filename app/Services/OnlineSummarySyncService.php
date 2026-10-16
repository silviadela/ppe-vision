<?php

namespace App\Services;

use App\Models\TrainingRun;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OnlineSummarySyncService
{
    public function __construct(private readonly MetricSummaryService $summaryService) {}

    public function configured(): bool
    {
        return filled(config('services.summary_sync.url'))
            && filled(config('services.summary_sync.token'));
    }

    public function generateAndPush(int $trainingRunId): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('SUMMARY_SYNC_URL atau SUMMARY_SYNC_TOKEN belum dikonfigurasi.');
        }

        $endpoint = rtrim((string) config('services.summary_sync.url'), '/')
            .'/api/local-ai/training-runs/'.$trainingRunId;
        $source = $this->client()->get($endpoint)->throw()->json();

        if (! is_array($source['run'] ?? null) || ! is_string($source['metrics_fingerprint'] ?? null)) {
            throw new RuntimeException('Respons metrics dari dashboard online tidak valid.');
        }

        $run = new TrainingRun;
        $run->forceFill($source['run']);
        $summary = $this->summaryService->generate($run);

        $saved = $this->client()->put($endpoint.'/summary', [
            'ai_summary' => $summary['text'],
            'summary_generated_by' => $summary['engine'],
            'metrics_fingerprint' => $source['metrics_fingerprint'],
        ])->throw()->json();

        return [
            'engine' => $summary['engine'],
            'summary_url' => $saved['summary_url'] ?? $source['summary_url'] ?? null,
        ];
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()
            ->withToken((string) config('services.summary_sync.token'))
            ->connectTimeout(10)
            ->timeout(30);
    }
}
