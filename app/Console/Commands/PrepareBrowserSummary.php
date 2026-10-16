<?php

namespace App\Console\Commands;

use App\Models\TrainingRun;
use App\Services\MetricSummaryService;
use App\Services\SummaryExchangeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;
use Throwable;

class PrepareBrowserSummary extends Command
{
    protected $signature = 'summary:prepare-browser
        {metrics_file : Path file ppe-metrics-run-*.json yang diunduh dari dashboard online}
        {--output= : Path keluaran JSON; default storage/app/private}';

    protected $description = 'Buat file summary tervalidasi dengan Ollama lokal untuk diunggah melalui browser';

    public function handle(
        SummaryExchangeService $exchange,
        MetricSummaryService $summaryService,
    ): int {
        try {
            $inputPath = $this->resolveInputPath((string) $this->argument('metrics_file'));
            $payload = json_decode(File::get($inputPath), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                throw new JsonException('Isi JSON bukan objek.');
            }

            $source = $exchange->validateMetricsPackage($payload);
            $run = new TrainingRun;
            $run->forceFill($source['run']);

            $summary = $summaryService->generate($run);
            $result = $exchange->resultPackage($source, $summary);
            $outputPath = $this->resolveOutputPath((int) $run->id);

            File::ensureDirectoryExists(dirname($outputPath));
            File::put($outputPath, json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            ));
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Summary berhasil dibuat oleh '.$summary['engine'].'.');
        $this->line('Unggah file ini pada halaman sinkronisasi online:');
        $this->line($outputPath);

        return self::SUCCESS;
    }

    private function resolveInputPath(string $path): string
    {
        $candidate = File::exists($path) ? $path : base_path($path);
        if (! File::isFile($candidate)) {
            throw new \RuntimeException('File metrics tidak ditemukan: '.$path);
        }

        return realpath($candidate) ?: $candidate;
    }

    private function resolveOutputPath(int $runId): string
    {
        $requested = $this->option('output');
        if (is_string($requested) && $requested !== '') {
            return str_starts_with($requested, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $requested)
                ? $requested
                : base_path($requested);
        }

        return storage_path('app/private/summary-sync-run-'.$runId.'.json');
    }
}
