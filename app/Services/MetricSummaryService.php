<?php

namespace App\Services;

use App\Models\TrainingRun;
use Illuminate\Support\Facades\Http;
use Throwable;

class MetricSummaryService
{
    public function generate(TrainingRun $run): array
    {
        if (config('services.ollama.enabled')) {
            try {
                $response = Http::timeout(90)->post(rtrim(config('services.ollama.url'), '/').'/api/generate', [
                    'model' => config('services.ollama.model'),
                    'stream' => false,
                    'prompt' => $this->prompt($run),
                ])->throw()->json();

                if (! empty($response['response'])) {
                    return ['text' => trim($response['response']), 'engine' => 'Ollama · '.config('services.ollama.model')];
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return ['text' => $this->localSummary($run), 'engine' => 'Analisis lokal berbasis aturan (bukan LLM)'];
    }

    private function prompt(TrainingRun $run): string
    {
        return 'Anda adalah analis computer vision. Ringkas hasil training PPE detection dalam Bahasa Indonesia. '
            .'Jelaskan kualitas model, kelas terlemah, risiko false positive/negative, dan rekomendasi konkret. '
            .'Jangan mengarang data. Data: '.json_encode([
                'settings' => $run->only(['model_version', 'epochs', 'batch_size', 'image_size']),
                'overall' => $run->metrics,
                'per_class' => $run->per_class_metrics,
            ], JSON_UNESCAPED_UNICODE);
    }

    private function localSummary(TrainingRun $run): string
    {
        $metrics = $run->metrics ?? [];
        $classes = collect($run->per_class_metrics ?? [])
            ->filter(fn ($item) => isset($item['map50_95']) || isset($item['map50']));
        $map = (float) ($metrics['map50_95'] ?? 0);
        $weakest = $classes
            ->sortBy(fn ($item) => $item['map50_95'] ?? $item['map50'] ?? 0)
            ->keys()
            ->first();
        $quality = $map >= .75 ? 'sangat baik' : ($map >= .55 ? 'cukup baik' : 'masih perlu ditingkatkan');

        $summary = "Model {$run->model_version} memiliki performa {$quality} dengan mAP50-95 ".number_format($map * 100, 1).'%. ';
        if ($weakest) {
            $usesStrictMap = isset($classes[$weakest]['map50_95']);
            $weakMetric = $classes[$weakest][$usesStrictMap ? 'map50_95' : 'map50'];
            $metricLabel = $usesStrictMap ? 'mAP50-95' : 'mAP50';
            $summary .= "Kelas yang paling perlu diperhatikan adalah {$weakest} ({$metricLabel} ".number_format($weakMetric * 100, 1).'%). ';
        }
        $summary .= 'Prioritaskan penambahan data pada kondisi cahaya rendah, objek kecil, dan kasus tertutup sebagian. '
            .'Periksa confusion matrix sebelum deployment dan uji confidence threshold pada video lapangan untuk menyeimbangkan false positive dan false negative.';

        return $summary;
    }
}
