<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingRun extends Model
{
    protected $fillable = [
        'name', 'model_version', 'status', 'dataset_name', 'epochs', 'batch_size',
        'image_size', 'optimizer', 'device', 'started_at', 'finished_at', 'metrics',
        'per_class_metrics', 'confusion_matrix', 'training_history', 'ai_summary',
        'summary_generated_by', 'model_artifact_path', 'results_plot_path',
        'confusion_matrix_path', 'confusion_matrix_normalized_path', 'pr_curve_path', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'metrics' => 'array',
            'per_class_metrics' => 'array',
            'confusion_matrix' => 'array',
            'training_history' => 'array',
        ];
    }

    public function detections(): HasMany
    {
        return $this->hasMany(Detection::class);
    }
}
