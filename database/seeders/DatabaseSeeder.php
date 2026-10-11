<?php

namespace Database\Seeders;

use App\Models\TrainingRun;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $run = TrainingRun::where('dataset_name', 'PPE Detection v1')
            ->where('notes', 'like', 'Data contoh%')
            ->first() ?? TrainingRun::firstOrNew(['name' => 'PPE YOLO11s Best Config']);

        $run->fill([
            'name' => 'PPE YOLO11s Best Config',
            'model_version' => 'YOLO11s',
            'status' => 'completed',
            'dataset_name' => 'Personal Protective Equipment - Combined Model 1',
            'epochs' => 60,
            'batch_size' => 32,
            'image_size' => 640,
            'optimizer' => 'AdamW',
            'device' => 'Google Colab GPU',
            'finished_at' => '2026-10-11 09:26:22',
            'metrics' => [
                'precision' => .72450,
                'recall' => .87209,
                'map50' => .77007,
                'map50_95' => .54627,
                'val_box_loss' => 1.12413,
                'val_cls_loss' => 1.41645,
                'val_dfl_loss' => 1.05325,
                'fitness' => .54627,
                'best_epoch' => 50,
            ],
            'per_class_metrics' => [
                'helm' => ['source_class' => 'Hardhat'],
                'masker' => ['source_class' => 'Mask'],
                'people' => ['source_class' => 'Person'],
            ],
            'training_history' => $this->readTrainingHistory(),
            'model_artifact_path' => 'inference_service/models/best.pt',
            'results_plot_path' => 'training/ppe-yolo11s-bestconfig/results.png',
            'confusion_matrix_path' => 'training/ppe-yolo11s-bestconfig/confusion_matrix.png',
            'confusion_matrix_normalized_path' => 'training/ppe-yolo11s-bestconfig/confusion_matrix_normalized.png',
            'pr_curve_path' => 'training/ppe-yolo11s-bestconfig/BoxPR_curve.png',
            'notes' => 'Hasil training asli Google Colab. Kelas model Hardhat, Mask, Person dinormalisasi oleh inference API menjadi Helm, Masker, People. Metrics per kelas tidak tersedia di artefak training.',
        ]);
        $run->save();
    }

    private function readTrainingHistory(): array
    {
        $handle = fopen(database_path('data/ppe_yolo11s_bestconfig_results.csv'), 'r');
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
