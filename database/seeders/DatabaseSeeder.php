<?php

namespace Database\Seeders;

use App\Models\TrainingRun;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $run = TrainingRun::where('model_artifact_path', 'inference_service/models/best.pt')
            ->first() ?? TrainingRun::whereIn('name', [
                'PPE YOLO11s Best Config',
                'PPE YOLO11s New Dataset v1',
                'PPE YOLO11s New Dataset v1 (Model 2)',
                'PPE YOLO26s Dataset 4 Final',
            ])->first() ?? new TrainingRun;

        $run->fill([
            'name' => 'PPE YOLO26s Dataset 4 Final',
            'model_version' => 'YOLO26s',
            'status' => 'completed',
            'dataset_name' => 'PPE Detection and Compliance v5',
            'epochs' => 60,
            'batch_size' => 16,
            'image_size' => 640,
            'optimizer' => 'AdamW',
            'device' => 'Google Colab GPU',
            'finished_at' => '2026-10-13 22:11:17',
            'metrics' => [
                'evaluation_split' => 'test',
                'precision' => .9548221930,
                'recall' => .9070161537,
                'f1' => .9303054199,
                'map50' => .9570234938,
                'map50_95' => .6929913951,
                'validation' => [
                    'precision' => .9301602307,
                    'recall' => .9203828735,
                    'f1' => .9252457222,
                    'map50' => .9480501096,
                    'map50_95' => .6637943567,
                ],
                'best_epoch' => 58,
                'dataset_split_images' => [
                    'train' => 3025,
                    'validation' => 603,
                    'test' => 212,
                ],
                'artifacts' => [
                    'f1_curve' => 'training/ppe-yolo26s-dataset4-final/BoxF1_curve.png',
                    'precision_curve' => 'training/ppe-yolo26s-dataset4-final/BoxP_curve.png',
                    'recall_curve' => 'training/ppe-yolo26s-dataset4-final/BoxR_curve.png',
                    'label_distribution' => 'training/ppe-yolo26s-dataset4-final/labels.jpg',
                    'validation_prediction' => 'training/ppe-yolo26s-dataset4-final/val_batch0_pred.jpg',
                ],
            ],
            'per_class_metrics' => [
                'helm' => ['source_class' => 'Helmet', 'precision' => .9632154123, 'recall' => .8952287890, 'f1' => .9279785382, 'map50' => .9624886079, 'map50_95' => .6905393419],
                'masker' => ['source_class' => 'Mask', 'precision' => .9287713636, 'recall' => .875, 'f1' => .9010842051, 'map50' => .9313001169, 'map50_95' => .6339617180],
                'people' => ['source_class' => 'Person', 'precision' => .9724798032, 'recall' => .9508196721, 'f1' => .9615277694, 'map50' => .9772817565, 'map50_95' => .7544731253],
            ],
            'training_history' => $this->readTrainingHistory(),
            'ai_summary' => "### Kesimpulan\n"
                ."Model YOLO26s pada test independen (212 gambar) mencapai precision 95,5%, recall 90,7%, F1 93,0%, mAP50 95,7%, dan mAP50-95 69,3%.\n\n"
                ."### mAP50-95 per kelas\n"
                ."- Helm: 69,1%\n- Masker: 63,4%\n- People: 75,4%\n\n"
                ."### Kelas yang perlu perhatian\n"
                ."Masker merupakan kelas terlemah secara relatif berdasarkan mAP50-95 63,4%.\n\n"
                ."### Risiko operasional\n"
                ."Kondisi lapangan yang tidak stabil dapat memengaruhi performa model, terutama pada kelas Masker dengan risiko false negative yang lebih tinggi.\n\n"
                ."### Rekomendasi\n"
                ."1. Tambah variasi data kelas Masker, terutama objek kecil, sudut berbeda, dan kondisi cahaya rendah.\n"
                ."2. Analisis false negative dan confusion matrix untuk memahami kesalahan pada kelas Masker.\n"
                ."3. Uji confidence threshold pada video lapangan untuk menyeimbangkan false positive dan false negative.",
            'summary_generated_by' => 'Ollama · llama3.2:3b (dibuat secara lokal)',
            'model_artifact_path' => 'inference_service/models/best.pt',
            'results_plot_path' => 'training/ppe-yolo26s-dataset4-final/results.png',
            'confusion_matrix_path' => 'training/ppe-yolo26s-dataset4-final/confusion_matrix.png',
            'confusion_matrix_normalized_path' => 'training/ppe-yolo26s-dataset4-final/confusion_matrix_normalized.png',
            'pr_curve_path' => 'training/ppe-yolo26s-dataset4-final/BoxPR_curve.png',
            'notes' => 'Lima trial random search dijalankan selama 20 epoch pada 60% data. Trial 4 menghasilkan skor gabungan terbaik dan hyperparameter-nya dipakai untuk final training 60 epoch pada seluruh data train. Metrics utama dan metrics per kelas berasal dari split test independen; training curve berasal dari split validation.',
        ]);
        $run->save();
    }

    private function readTrainingHistory(): array
    {
        $handle = fopen(database_path('data/ppe_yolo26s_dataset4_final_results.csv'), 'r');
        if ($handle === false) {
            return [];
        }

        $headers = fgetcsv($handle) ?: [];
        $history = [];
        while (($values = fgetcsv($handle)) !== false) {
            $row = array_combine($headers, $values);
            if ($row === false) {
                continue;
            }

            $history[] = [
                'epoch' => (int) $row['epoch'],
                'precision' => (float) $row['metrics/precision(B)'],
                'recall' => (float) $row['metrics/recall(B)'],
                'map50' => (float) $row['metrics/mAP50(B)'],
                'map50_95' => (float) $row['metrics/mAP50-95(B)'],
                'train_box_loss' => (float) $row['train/box_loss'],
                'train_cls_loss' => (float) $row['train/cls_loss'],
                'val_box_loss' => (float) $row['val/box_loss'],
                'val_cls_loss' => (float) $row['val/cls_loss'],
            ];
        }
        fclose($handle);

        return $history;
    }
}
