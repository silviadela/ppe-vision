<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrainingRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocalAiSummaryController extends Controller
{
    public function show(TrainingRun $trainingRun): JsonResponse
    {
        abort_if(empty($trainingRun->metrics), 422, 'Metrics belum tersedia.');

        return response()->json([
            'run' => $trainingRun->only([
                'id', 'name', 'model_version', 'status', 'dataset_name', 'epochs',
                'batch_size', 'image_size', 'optimizer', 'device', 'metrics',
                'per_class_metrics',
            ]),
            'metrics_fingerprint' => $this->metricsFingerprint($trainingRun),
            'summary_url' => route('summary.show', $trainingRun),
        ]);
    }

    public function update(Request $request, TrainingRun $trainingRun): JsonResponse
    {
        abort_if(empty($trainingRun->metrics), 422, 'Metrics belum tersedia.');

        $validated = $request->validate([
            'ai_summary' => ['required', 'string', 'max:20000'],
            'summary_generated_by' => ['required', 'string', 'max:255'],
            'metrics_fingerprint' => ['required', 'string', 'size:64'],
        ]);

        abort_unless(
            hash_equals($this->metricsFingerprint($trainingRun), $validated['metrics_fingerprint']),
            409,
            'Metrics online berubah. Ambil metrics terbaru lalu buat ulang summary.',
        );

        $expectedEngine = 'Ollama · '.config('services.ollama.model');
        abort_unless(
            hash_equals($expectedEngine, $validated['summary_generated_by']),
            422,
            'Label mesin AI tidak sesuai dengan konfigurasi Ollama.',
        );

        $trainingRun->update([
            'ai_summary' => $validated['ai_summary'],
            'summary_generated_by' => $validated['summary_generated_by'],
        ]);

        return response()->json([
            'message' => 'Summary Ollama tersimpan di database online.',
            'summary_url' => route('summary.show', $trainingRun),
        ]);
    }

    private function metricsFingerprint(TrainingRun $trainingRun): string
    {
        return hash('sha256', json_encode([
            'model_version' => $trainingRun->model_version,
            'metrics' => $trainingRun->metrics,
            'per_class_metrics' => $trainingRun->per_class_metrics,
        ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }
}
