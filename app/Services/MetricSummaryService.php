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
                // Model lokal dapat membutuhkan lebih dari batas default PHP 60 detik
                // saat pertama kali dimuat, khususnya pada mesin tanpa GPU diskret.
                set_time_limit(210);

                $response = Http::connectTimeout(5)->timeout(180)->post(rtrim(config('services.ollama.url'), '/').'/api/generate', [
                    'model' => config('services.ollama.model'),
                    'stream' => false,
                    'prompt' => $this->prompt($run),
                    'format' => $this->responseSchema(),
                    'options' => [
                        'temperature' => 0.2,
                        'num_predict' => 360,
                    ],
                ])->throw()->json();

                if (! empty($response['response'])) {
                    $text = $this->formatOllamaResponse($run, $response['response']);

                    // Jangan memberi label Ollama jika respons tidak lolos validasi
                    // dan aplikasi harus menggunakan ringkasan berbasis aturan.
                    if ($text !== null) {
                        return [
                            'text' => $text,
                            'engine' => 'Ollama · '.config('services.ollama.model'),
                        ];
                    }
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return ['text' => $this->localSummary($run), 'engine' => 'Analisis lokal berbasis aturan (bukan LLM)'];
    }

    private function prompt(TrainingRun $run): string
    {
        $percentage = fn ($value) => number_format((float) $value * 100, 1, ',', '.').'%';
        $metrics = $run->metrics ?? [];
        $perClass = collect($run->per_class_metrics ?? [])
            ->map(fn ($item) => ['map50_95' => $percentage($item['map50_95'] ?? 0)])
            ->all();
        $weakestClass = collect($run->per_class_metrics ?? [])
            ->filter(fn ($item) => isset($item['map50_95']))
            ->sortBy('map50_95')
            ->keys()
            ->first();

        $data = [
            'model' => $run->model_version,
            'settings' => $run->only(['epochs', 'batch_size', 'image_size', 'optimizer']),
            'evaluation_split' => $metrics['evaluation_split'] ?? 'validation',
            'overall' => [
                'precision' => $percentage($metrics['precision'] ?? 0),
                'recall' => $percentage($metrics['recall'] ?? 0),
                'f1' => $percentage($metrics['f1'] ?? 0),
                'map50' => $percentage($metrics['map50'] ?? 0),
                'map50_95' => $percentage($metrics['map50_95'] ?? 0),
            ],
            'per_class' => $perClass,
            'weakest_class_by_map50_95' => $weakestClass,
        ];

        return 'Anda adalah analis computer vision. Isi keluaran JSON terstruktur dengan analisis evaluasi model PPE '
            .'dalam Bahasa Indonesia berdasarkan data yang diberikan. Jangan menulis angka, persentase, atau istilah accuracy; '
            .'aplikasi akan menampilkan seluruh angka secara otomatis dari database. '
            .'Kelas terlemah sudah dihitung pada weakest_class_by_map50_95; risiko dan saran harus berfokus pada kelas tersebut. '
            .'Isi operational_risk maksimal dua kalimat tanpa mengulang kesimpulan metrik. Isi recommendations '
            .'dengan tepat tiga saran singkat: perbaikan data untuk kelas terlemah, analisis false negative/confusion matrix, '
            .'dan pengujian confidence threshold pada kondisi lapangan. Hindari pengulangan dan jangan menyarankan mengganti model tanpa bukti. Data: '
            .json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'operational_risk' => ['type' => 'string'],
                'recommendations' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'minItems' => 3,
                    'maxItems' => 3,
                ],
            ],
            'required' => ['operational_risk', 'recommendations'],
        ];
    }

    private function formatOllamaResponse(TrainingRun $run, string $response): ?string
    {
        $content = json_decode($response, true);
        if (! is_array($content)
            || ! is_string($content['operational_risk'] ?? null)
            || ! is_array($content['recommendations'] ?? null)) {
            return null;
        }

        $risk = trim($content['operational_risk']);
        $recommendations = collect($content['recommendations'])
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn ($item) => trim($item))
            ->take(3)
            ->values();

        if ($risk === ''
            || strlen($risk) > 1200
            || count($content['recommendations']) !== 3
            || $recommendations->count() !== 3
            || $recommendations->contains(fn ($item) => strlen($item) > 600)) {
            return null;
        }

        $weakest = collect($run->per_class_metrics ?? [])
            ->filter(fn ($item) => isset($item['map50_95']))
            ->sortBy('map50_95')
            ->keys()
            ->first();
        $recommendations = $recommendations->all();
        $generatedText = strtolower($risk.' '.implode(' ', $recommendations));
        if (($weakest && ! str_contains($generatedText, strtolower((string) $weakest)))
            || ! str_contains(strtolower($recommendations[1]), 'confusion')
            || ! str_contains(strtolower($recommendations[2]), 'confidence')
            || str_contains($generatedText, 'akurasi')
            || preg_match('/\d/u', $generatedText)) {
            return null;
        }

        return $this->structuredSummary(
            $run,
            $risk,
            $recommendations,
        );
    }

    private function localSummary(TrainingRun $run): string
    {
        return $this->structuredSummary(
            $run,
            'Risiko utama adalah false negative pada objek kecil, tertutup sebagian, atau berada dalam pencahayaan rendah.',
            [
                'Tambah variasi data untuk kelas terlemah, terutama objek kecil, sudut berbeda, dan kondisi cahaya rendah.',
                'Tinjau false negative dan confusion matrix sebelum model dipakai pada kondisi nyata.',
                'Uji beberapa confidence threshold pada video lapangan untuk menyeimbangkan false positive dan false negative.',
            ],
        );
    }

    private function structuredSummary(TrainingRun $run, string $risk, array $recommendations): string
    {
        $percentage = fn ($value) => number_format((float) $value * 100, 1, ',', '.').'%';
        $metrics = $run->metrics ?? [];
        $classes = collect($run->per_class_metrics ?? [])
            ->filter(fn ($item) => isset($item['map50_95']) || isset($item['map50']));
        $usesStrictMap = $classes->contains(fn ($item) => isset($item['map50_95']));
        $classMetricKey = $usesStrictMap ? 'map50_95' : 'map50';
        $classMetricLabel = $usesStrictMap ? 'mAP50-95' : 'mAP50';
        $classes = $classes->filter(fn ($item) => isset($item[$classMetricKey]));
        $weakest = $classes->sortBy($classMetricKey)->keys()->first();
        $testImages = $metrics['dataset_split_images']['test'] ?? null;
        $precision = (float) ($metrics['precision'] ?? 0);
        $recall = (float) ($metrics['recall'] ?? 0);
        $f1 = isset($metrics['f1'])
            ? (float) $metrics['f1']
            : ($precision + $recall > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0);

        $conclusion = "Model {$run->model_version} pada test independen"
            .($testImages ? " ({$testImages} gambar)" : '')
            .' mencapai precision '.$percentage($precision)
            .', recall '.$percentage($recall)
            .', F1 '.$percentage($f1)
            .', mAP50 '.$percentage($metrics['map50'] ?? 0)
            .', dan mAP50-95 '.$percentage($metrics['map50_95'] ?? 0).'.';

        $classMetrics = $classes
            ->map(fn ($item, $class) => '- '.ucfirst((string) $class).': '.$percentage($item[$classMetricKey]))
            ->implode("\n");

        $weakestText = $weakest
            ? ucfirst((string) $weakest).' merupakan kelas terlemah secara relatif berdasarkan '.$classMetricLabel.' '
                .$percentage($classes[$weakest][$classMetricKey]).'.'
            : 'Metrik per kelas belum tersedia.';

        $numberedRecommendations = collect($recommendations)
            ->take(3)
            ->values()
            ->map(fn ($item, $index) => ($index + 1).'. '.trim((string) $item))
            ->implode("\n");

        return "### Kesimpulan\n{$conclusion}"
            ."\n\n### {$classMetricLabel} per kelas\n{$classMetrics}"
            ."\n\n### Kelas yang perlu perhatian\n{$weakestText}"
            ."\n\n### Risiko operasional\n{$risk}"
            ."\n\n### Rekomendasi\n{$numberedRecommendations}";
    }
}
