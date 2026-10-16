<?php

namespace App\Services;

use App\Models\TrainingRun;
use RuntimeException;

class SummaryExchangeService
{
    public const METRICS_SCHEMA = 'ppe-summary-metrics-v1';

    public const RESULT_SCHEMA = 'ppe-summary-result-v1';

    private const RUN_FIELDS = [
        'id', 'name', 'model_version', 'status', 'dataset_name', 'epochs',
        'batch_size', 'image_size', 'optimizer', 'device', 'metrics',
        'per_class_metrics',
    ];

    public function tokenIsValid(?string $provided): bool
    {
        $expected = (string) config('services.summary_sync.token');

        return $expected !== ''
            && is_string($provided)
            && $provided !== ''
            && hash_equals($expected, $provided);
    }

    public function metricsPackage(TrainingRun $run): array
    {
        $this->ensureTokenIsConfigured();
        $runData = $run->only(self::RUN_FIELDS);
        $fingerprint = $this->fingerprintFromData($runData);

        return [
            'schema' => self::METRICS_SCHEMA,
            'exported_at' => now()->toIso8601String(),
            'run' => $runData,
            'metrics_fingerprint' => $fingerprint,
            'source_signature' => $this->sourceSignature((int) $run->id, $fingerprint),
        ];
    }

    public function validateMetricsPackage(array $payload): array
    {
        $this->ensureTokenIsConfigured();

        if (($payload['schema'] ?? null) !== self::METRICS_SCHEMA
            || ! is_array($payload['run'] ?? null)
            || ! is_string($payload['metrics_fingerprint'] ?? null)
            || ! is_string($payload['source_signature'] ?? null)
            || ! is_numeric($payload['run']['id'] ?? null)
            || ! is_array($payload['run']['metrics'] ?? null)
            || ! is_array($payload['run']['per_class_metrics'] ?? null)) {
            throw new RuntimeException('Struktur file metrics tidak valid. Unduh ulang dari dashboard online.');
        }

        $fingerprint = $this->fingerprintFromData($payload['run']);
        if (! hash_equals($fingerprint, $payload['metrics_fingerprint'])) {
            throw new RuntimeException('Isi file metrics berubah setelah diunduh. Unduh ulang file yang asli.');
        }

        $expectedSignature = $this->sourceSignature((int) $payload['run']['id'], $fingerprint);
        if (! hash_equals($expectedSignature, $payload['source_signature'])) {
            throw new RuntimeException('Tanda tangan file metrics tidak valid. Periksa SUMMARY_SYNC_TOKEN lokal.');
        }

        return $payload;
    }

    public function resultPackage(array $source, array $summary): array
    {
        $source = $this->validateMetricsPackage($source);

        if (! is_string($summary['text'] ?? null) || ! is_string($summary['engine'] ?? null)) {
            throw new RuntimeException('Hasil Ollama tidak dapat dibuat menjadi paket sinkronisasi.');
        }

        return [
            'schema' => self::RESULT_SCHEMA,
            'generated_at' => now()->toIso8601String(),
            'training_run_id' => (int) $source['run']['id'],
            'metrics_fingerprint' => $source['metrics_fingerprint'],
            'source_signature' => $source['source_signature'],
            'ai_summary' => $summary['text'],
            'summary_generated_by' => $summary['engine'],
        ];
    }

    public function validateResultPackage(TrainingRun $run, array $payload): array
    {
        $this->ensureTokenIsConfigured();

        if (($payload['schema'] ?? null) !== self::RESULT_SCHEMA
            || (int) ($payload['training_run_id'] ?? 0) !== (int) $run->id
            || ! is_string($payload['metrics_fingerprint'] ?? null)
            || ! is_string($payload['source_signature'] ?? null)
            || ! is_string($payload['ai_summary'] ?? null)
            || ! is_string($payload['summary_generated_by'] ?? null)) {
            throw new RuntimeException('Struktur file summary tidak valid atau ID eksperimen tidak sesuai.');
        }

        $currentFingerprint = $this->metricsFingerprint($run);
        if (! hash_equals($currentFingerprint, $payload['metrics_fingerprint'])) {
            throw new RuntimeException('Metrics online sudah berubah. Unduh metrics terbaru dan buat ulang summary.');
        }

        $expectedSignature = $this->sourceSignature((int) $run->id, $currentFingerprint);
        if (! hash_equals($expectedSignature, $payload['source_signature'])) {
            throw new RuntimeException('Tanda tangan sumber tidak valid. File ditolak.');
        }

        $expectedEngine = 'Ollama · '.config('services.ollama.model');
        if (! hash_equals($expectedEngine, $payload['summary_generated_by'])) {
            throw new RuntimeException('Summary bukan berasal dari model Ollama yang dikonfigurasi.');
        }

        $summary = trim($payload['ai_summary']);
        if ($summary === '' || strlen($summary) > 20000
            || ! str_contains($summary, '### Kesimpulan AI')
            || ! str_contains($summary, '### Metrik tervalidasi dari database')
            || ! str_contains($summary, '### Rekomendasi')) {
            throw new RuntimeException('Isi summary tidak lengkap atau tidak mengikuti format tervalidasi.');
        }

        return [
            'ai_summary' => $summary,
            'summary_generated_by' => $payload['summary_generated_by'],
        ];
    }

    public function metricsFingerprint(TrainingRun $run): string
    {
        return $this->fingerprintFromData($run->only(self::RUN_FIELDS));
    }

    private function fingerprintFromData(array $run): string
    {
        // InfinityFree memakai serialize_precision=53, sedangkan banyak instalasi
        // PHP lokal memakai -1. Paksa representasi float yang sama agar angka
        // identik tidak menghasilkan fingerprint berbeda antar-server.
        $previousPrecision = ini_get('serialize_precision');

        try {
            ini_set('serialize_precision', '53');

            return hash('sha256', json_encode([
                'model_version' => $run['model_version'] ?? null,
                'metrics' => $run['metrics'] ?? null,
                'per_class_metrics' => $run['per_class_metrics'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        } finally {
            ini_set('serialize_precision', (string) $previousPrecision);
        }
    }

    private function sourceSignature(int $runId, string $fingerprint): string
    {
        return hash_hmac(
            'sha256',
            self::METRICS_SCHEMA.'|'.$runId.'|'.$fingerprint,
            (string) config('services.summary_sync.token'),
        );
    }

    private function ensureTokenIsConfigured(): void
    {
        if (! filled(config('services.summary_sync.token'))) {
            throw new RuntimeException('SUMMARY_SYNC_TOKEN belum dikonfigurasi.');
        }
    }
}
