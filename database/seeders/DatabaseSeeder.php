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
                'precision' => .93930,
                'recall' => .90805,
                'map50' => .95095,
                'map50_95' => .67852,
                'val_box_loss' => 1.00014,
                'val_cls_loss' => .49519,
                'val_dfl_loss' => 1.05783,
                'fitness' => .70576,
                'best_epoch' => 60,
            ],
            'per_class_metrics' => [
                'helm' => ['source_class' => 'Helmet'],
                'masker' => ['source_class' => 'Mask'],
                'people' => ['source_class' => 'Person'],
            ],
            'training_history' => $this->readTrainingHistory(),
            'model_artifact_path' => 'inference_service/models/best.pt',
            'results_plot_path' => 'training/ppe-yolo11s-newdataset-v1/results.png',
            'confusion_matrix_path' => 'training/ppe-yolo11s-newdataset-v1/confusion_matrix.png',
            'confusion_matrix_normalized_path' => 'training/ppe-yolo11s-newdataset-v1/confusion_matrix_normalized.png',
            'pr_curve_path' => 'training/ppe-yolo11s-newdataset-v1/BoxPR_curve.png',
            'notes' => 'Hasil training Google Colab menggunakan dataset baru. Kelas model Helmet, Mask, Person dinormalisasi menjadi Helm, Masker, People. Metrics per kelas tidak tersedia di artefak training.',
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
