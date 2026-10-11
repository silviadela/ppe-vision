<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_runs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('model_version')->default('YOLO');
            $table->string('status')->default('draft');
            $table->string('dataset_name')->nullable();
            $table->unsignedInteger('epochs');
            $table->unsignedInteger('batch_size');
            $table->unsignedInteger('image_size');
            $table->string('optimizer')->nullable();
            $table->string('device')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('metrics')->nullable();
            $table->json('per_class_metrics')->nullable();
            $table->json('confusion_matrix')->nullable();
            $table->json('training_history')->nullable();
            $table->text('ai_summary')->nullable();
            $table->string('summary_generated_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_runs');
    }
};
