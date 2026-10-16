<?php

namespace App\Http\Controllers;

use App\Models\TrainingRun;
use App\Services\MetricSummaryService;
use Throwable;

class SummaryController extends Controller
{
    public function show(TrainingRun $trainingRun)
    {
        return view('summary.show', ['run' => $trainingRun]);
    }

    public function generate(
        int $trainingRun,
        MetricSummaryService $service,
    ) {
        abort_unless(config('deployment.allow_admin_writes'), 404);

        try {
            $localRun = TrainingRun::findOrFail($trainingRun);
            abort_if(empty($localRun->metrics), 422, 'Metrics belum tersedia.');
            $summary = $service->generate($localRun);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }

        $localRun->update([
            'ai_summary' => $summary['text'],
            'summary_generated_by' => $summary['engine'],
        ]);

        return back()->with('success', 'Ringkasan berhasil dibuat.');
    }
}
