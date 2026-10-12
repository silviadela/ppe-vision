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
            ])->first() ?? new TrainingRun;

        $run->fill([
            'name' => 'PPE YOLO11s New Dataset v1',
            'model_version' => 'YOLO11s',
            'status' => 'completed',
            'dataset_name' => 'Person Mask Helmet v2 (resplit)',
            'epochs' => 60,
            'batch_size' => 32,
            'image_size' => 640,
            'optimizer' => 'AdamW',
            'device' => 'Google Colab GPU',
            'finished_at' => '2026-10-11 12:26:55',
            'metrics' => [
                'precision' => .93318,
                'recall' => .89616,
                'map50' => .93655,
                'map50_95' => .66467,
                'val_box_loss' => 1.00712,
                'val_cls_loss' => .53276,
                'val_dfl_loss' => 1.07003,
                'fitness' => .66467,
                'best_epoch' => 60,
                'class_distribution' => [
                    'helm' => 6427,
                    'masker' => 11231,
                    'people' => 6143,
                ],
                'artifacts' => [
                    'f1_curve' => 'training/ppe-yolo11s-newdataset-v1/BoxF1_curve.png',
                    'precision_curve' => 'training/ppe-yolo11s-newdataset-v1/BoxP_curve.png',
                    'recall_curve' => 'training/ppe-yolo11s-newdataset-v1/BoxR_curve.png',
                    'label_distribution' => 'training/ppe-yolo11s-newdataset-v1/labels.jpg',
                    'validation_prediction' => 'training/ppe-yolo11s-newdataset-v1/val_batch0_pred.jpg',
                ],
            ],
            'per_class_metrics' => [
                'helm' => ['source_class' => 'Helmet', 'map50' => .975],
                'masker' => ['source_class' => 'Mask', 'map50' => .922],
                'people' => ['source_class' => 'Person', 'map50' => .913],
            ],
            'training_history' => $this->readTrainingHistory(),
            'ai_summary' => 'Model YOLO11s menunjukkan performa validasi yang kuat pada IoU 0,50 dengan precision 93,3%, recall 89,6%, dan mAP50 93,7%. Namun, mAP50-95 sebesar 66,5% menunjukkan ketepatan lokasi bounding box masih menurun pada ambang IoU yang lebih ketat. Berdasarkan mAP50 per kelas, Helmet merupakan kelas terkuat (97,5%), diikuti Mask (92,2%) dan Person (91,3%). Pengujian lapangan sebaiknya memprioritaskan objek kecil, wajah tertutup, pencahayaan rendah, dan sudut kamera yang berbeda dari data training. Gunakan confidence awal 0,20 lalu evaluasi false positive dan false negative pada gambar yang benar-benar berasal dari kamera demo.',
            'summary_generated_by' => 'OpenAI Codex (AI-assisted baseline)',
            'model_artifact_path' => 'inference_service/models/best.pt',
            'results_plot_path' => 'training/ppe-yolo11s-newdataset-v1/results.png',
            'confusion_matrix_path' => 'training/ppe-yolo11s-newdataset-v1/confusion_matrix.png',
            'confusion_matrix_normalized_path' => 'training/ppe-yolo11s-newdataset-v1/confusion_matrix_normalized.png',
            'pr_curve_path' => 'training/ppe-yolo11s-newdataset-v1/BoxPR_curve.png',
            'notes' => 'Hasil training terbaru Google Colab selesai 12 Oktober 2026 menggunakan dataset baru. Kelas model Helmet, Mask, Person dinormalisasi menjadi Helm, Masker, People. Nilai per kelas adalah mAP50 yang dibaca dari Precision-Recall Curve Ultralytics.',
        ]);
        $run->save();
    }

    private function readTrainingHistory(): array
    {
        $handle = fopen(database_path('data/ppe_yolo11s_newdataset_v1_results.csv'), 'r');
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
