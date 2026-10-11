<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Detection extends Model
{
    protected $fillable = [
        'training_run_id', 'source_name', 'source_path', 'output_path', 'detections',
        'inference_ms', 'mode', 'status', 'error_message',
    ];

    protected function casts(): array
    {
        return ['detections' => 'array'];
    }

    public function trainingRun(): BelongsTo
    {
        return $this->belongsTo(TrainingRun::class);
    }
}
