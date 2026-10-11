<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_name');
            $table->string('source_path');
            $table->string('output_path')->nullable();
            $table->json('detections')->nullable();
            $table->decimal('inference_ms', 10, 2)->nullable();
            $table->string('mode')->default('api');
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detections');
    }
};
