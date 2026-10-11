<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_runs', function (Blueprint $table) {
            $table->string('model_artifact_path')->nullable()->after('training_history');
            $table->string('results_plot_path')->nullable()->after('model_artifact_path');
            $table->string('confusion_matrix_path')->nullable()->after('results_plot_path');
            $table->string('confusion_matrix_normalized_path')->nullable()->after('confusion_matrix_path');
            $table->string('pr_curve_path')->nullable()->after('confusion_matrix_normalized_path');
        });
    }

    public function down(): void
    {
        Schema::table('training_runs', function (Blueprint $table) {
            $table->dropColumn([
                'model_artifact_path',
                'results_plot_path',
                'confusion_matrix_path',
                'confusion_matrix_normalized_path',
                'pr_curve_path',
            ]);
        });
    }
};
